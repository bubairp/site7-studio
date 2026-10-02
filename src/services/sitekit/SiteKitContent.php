<?php

namespace site7\studio\services\sitekit;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\helpers\FileHelper;

/**
 * Full Site Kit phase 2: content (docs/48_FULL_SITE_KIT.md §10).
 *
 * Copies live content rows table by table instead of re-saving elements
 * through the API, so every field type's stored value travels exactly
 * (CKEditor, SEO, Matrix, plugin fields). This is only sound because the
 * target is fresh and got the source's project config first:
 * - element IDs are kept, so relations, nested entries, structures and
 *   plugin rows that point at elements need no remapping;
 * - structural IDs (sites, sections, fields, entry types, field layouts,
 *   volumes, groups, structures) differ per install but their UIDs are
 *   identical, so those columns travel as "@uid:<uid>" and are resolved
 *   on the target.
 * Users never travel; author/uploader columns point at the target's first
 * admin. Drafts, revisions and soft-deleted elements are left out.
 */
class SiteKitContent extends Component
{
    /** Element types whose rows travel. */
    public const ELEMENT_TYPES = [Entry::class, Asset::class, Category::class, Tag::class, 'craft\elements\ContentBlock'];

    /**
     * Core content tables in insert order: element-id column(s) used to
     * filter them, and structural columns => the table their UIDs live in
     * ('@user' = the target's admin, '@folder' = volume folder).
     */
    public const CORE_TABLES = [
        'elements' => ['filter' => ['id'], 'structural' => ['fieldLayoutId' => 'fieldlayouts']],
        'elements_sites' => ['filter' => ['elementId'], 'structural' => ['siteId' => 'sites']],
        'elements_owners' => ['filter' => ['elementId', 'ownerId'], 'structural' => []],
        'entries' => ['filter' => ['id'], 'structural' => ['sectionId' => 'sections', 'typeId' => 'entrytypes', 'fieldId' => 'fields']],
        'entries_authors' => ['filter' => ['entryId'], 'structural' => ['authorId' => '@user']],
        'contentblocks' => ['filter' => ['id'], 'structural' => ['fieldId' => 'fields']],
        'categories' => ['filter' => ['id'], 'structural' => ['groupId' => 'categorygroups']],
        'tags' => ['filter' => ['id'], 'structural' => ['groupId' => 'taggroups']],
        'assets' => ['filter' => ['id'], 'structural' => ['volumeId' => 'volumes', 'folderId' => '@folder', 'uploaderId' => '@user']],
        'assets_sites' => ['filter' => ['assetId'], 'structural' => ['siteId' => 'sites']],
        'structureelements' => ['filter' => ['elementId'], 'structural' => ['structureId' => 'structures']],
        'relations' => ['filter' => ['sourceId', 'targetId'], 'structural' => ['fieldId' => 'fields', 'sourceSiteId' => 'sites']],
    ];

    /**
     * Plugin tables copied whole when the table exists on both sides, with
     * their site columns. Their element references (entry_id, element_id)
     * keep working because element IDs are kept.
     */
    public const PLUGIN_TABLES = [
        'simplerpmenu' => ['site_id' => 'sites'],
        'simplerpmenu_items' => [],
        'wheelform_forms' => ['site_id' => 'sites'],
        'wheelform_form_fields' => [],
        'sitemaps' => [],
    ];

    /**
     * Rows that are left out (and counted) when they point at a soft-deleted
     * structural row - leftovers such as a tree node in a deleted structure
     * or a relation of a deleted field. Elsewhere that's an error.
     */
    private const DROPPABLE_ON_DELETED_REF = ['structureelements', 'relations', 'elements_sites', 'assets_sites', 'entries_authors'];

    /** Structural columns cleared, rather than failing, when what they point at was deleted. */
    private const NULLABLE_DELETED_REFS = ['elements' => ['fieldLayoutId' => true]];

    /** Element-reference columns nulled when they point outside the export. */
    private const OPTIONAL_ELEMENT_REFS = [
        'elements' => ['canonicalId'],
        'entries' => ['parentId'],
        'categories' => ['parentId'],
    ];

    private const BATCH = 500;

    // -------------------------------------------------------------- export

    /**
     * Adds content/ to an open kit zip.
     *
     * @return array{counts: array<string, int>, assetFiles: int, tempFiles: string[]}
     *   tempFiles must be deleted after the zip is closed.
     */
    public function export(\ZipArchive $zip): array
    {
        $db = Craft::$app->getDb();
        if ($db->getIsPgsql()) {
            throw new \Exception('Exporting content is supported on MySQL only.');
        }

        $ids = $this->liveElementIds();
        $idSet = array_flip($ids);
        $uidMaps = [];
        $counts = [];
        $skipped = [];

        foreach (self::CORE_TABLES as $table => $rules) {
            $rows = $this->rowsFor($table, $rules['filter'], $idSet);
            if ($table === 'structureelements') {
                $rows = array_merge($rows, $this->structureRootRows($rows));
            }
            $kept = [];
            foreach ($rows as $row) {
                foreach (self::OPTIONAL_ELEMENT_REFS[$table] ?? [] as $column) {
                    if ($row[$column] !== null && !isset($idSet[$row[$column]])) {
                        $row[$column] = null;
                    }
                }
                foreach ($rules['structural'] as $column => $source) {
                    try {
                        $row[$column] = $this->toPortable($row[$column] ?? null, $source, $uidMaps);
                    } catch (DeletedReference $e) {
                        // Leftovers pointing at a soft-deleted structure/field/etc.
                        if (isset(self::NULLABLE_DELETED_REFS[$table][$column])) {
                            $row[$column] = null;
                            continue;
                        }
                        if (!in_array($table, self::DROPPABLE_ON_DELETED_REF, true)) {
                            throw new \Exception("{$table} row references {$e->getMessage()}, which was deleted on this site.");
                        }
                        $skipped[$table] = ($skipped[$table] ?? 0) + 1;
                        continue 2;
                    }
                }
                $kept[] = $this->portableSiteRefsInRow($row);
            }
            $zip->addFromString("content/tables/{$table}.json", json_encode($kept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $counts[$table] = count($kept);
        }

        foreach (self::PLUGIN_TABLES as $table => $structural) {
            if (!$db->tableExists("{{%{$table}}}")) {
                continue;
            }
            $rows = (new Query())->from("{{%{$table}}}")->all();
            foreach ($rows as &$row) {
                foreach ($structural as $column => $source) {
                    $row[$column] = $this->toPortable($row[$column] ?? null, $source, $uidMaps);
                }
                $row = $this->portableSiteRefsInRow($row);
            }
            unset($row);
            $zip->addFromString("content/plugin-tables/{$table}.json", json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $counts[$table] = count($rows);
        }

        $folders = [];
        foreach ((new Query())->from('{{%volumefolders}}')->all() as $folder) {
            try {
                $folder['volumeId'] = $this->toPortable($folder['volumeId'], 'volumes', $uidMaps);
            } catch (DeletedReference) {
                continue; // folder of a deleted volume
            }
            $folders[] = $folder;
        }
        $zip->addFromString('content/folders.json', json_encode($folders, JSON_UNESCAPED_SLASHES));

        [$assetFiles, $tempFiles] = $this->exportAssetFiles($zip, $ids);

        $zip->addFromString('content/meta.json', json_encode(['counts' => $counts, 'skipped' => $skipped, 'assetFiles' => $assetFiles], JSON_PRETTY_PRINT));

        return ['counts' => $counts, 'skipped' => $skipped, 'assetFiles' => $assetFiles, 'tempFiles' => $tempFiles];
    }

    /**
     * Live (not draft/revision/deleted) element IDs of the travelling types,
     * minus nested elements whose primary owner doesn't travel.
     *
     * @return int[]
     */
    private function liveElementIds(): array
    {
        $ids = array_map('intval', (new Query())
            ->select(['id'])
            ->from('{{%elements}}')
            ->where(['dateDeleted' => null, 'draftId' => null, 'revisionId' => null, 'type' => self::ELEMENT_TYPES])
            ->column());
        $set = array_flip($ids);

        $owned = [];
        foreach (['entries', 'contentblocks'] as $table) {
            if (Craft::$app->getDb()->tableExists("{{%{$table}}}")) {
                foreach ((new Query())->select(['id', 'primaryOwnerId'])->from("{{%{$table}}}")->where(['not', ['primaryOwnerId' => null]])->all() as $row) {
                    $owned[(int)$row['id']] = (int)$row['primaryOwnerId'];
                }
            }
        }

        do {
            $dropped = 0;
            foreach ($owned as $id => $ownerId) {
                if (isset($set[$id]) && !isset($set[$ownerId])) {
                    unset($set[$id]);
                    $dropped++;
                }
            }
        } while ($dropped > 0);

        return array_keys($set);
    }

    private function rowsFor(string $table, array $filterColumns, array $idSet): array
    {
        if (!Craft::$app->getDb()->tableExists("{{%{$table}}}")) {
            return [];
        }

        $rows = [];
        foreach ((new Query())->from("{{%{$table}}}")->each(1000) as $row) {
            foreach ($filterColumns as $column) {
                if (!isset($idSet[$row[$column]])) {
                    continue 2;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** Structure root nodes (elementId null) of the structures the exported nodes belong to. */
    private function structureRootRows(array $rows): array
    {
        $structureIds = array_values(array_unique(array_column($rows, 'structureId')));
        if (!$structureIds) {
            return [];
        }

        return (new Query())->from('{{%structureelements}}')->where(['structureId' => $structureIds, 'elementId' => null])->all();
    }

    /** Site IDs inside reference tags in any text column, e.g. Link field values. */
    private function portableSiteRefsInRow(array $row): array
    {
        $this->sourceSites ??= array_map('strval', (new Query())->select(['id', 'uid'])->from('{{%sites}}')->pairs());
        foreach ($row as $column => $value) {
            if (is_string($value) && str_contains($value, '@') && str_contains($value, '{')) {
                $row[$column] = SiteKitFiles::portableSiteRefs($value, $this->sourceSites);
            }
        }

        return $row;
    }

    private ?array $sourceSites = null;

    private ?array $targetSites = null;

    private function toPortable(mixed $value, string $source, array &$uidMaps): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($source === '@user') {
            return '@user';
        }
        if ($source === '@folder') {
            return '@folder:' . $value;
        }

        if (!isset($uidMaps[$source])) {
            // pairs() keys by the first column: id => uid. Soft-deleted rows
            // are left out: the target's project config never creates them.
            $query = (new Query())->select(['id', 'uid'])->from("{{%{$source}}}");
            if (Craft::$app->getDb()->columnExists("{{%{$source}}}", 'dateDeleted')) {
                $query->where(['dateDeleted' => null]);
            }
            $uidMaps[$source] = array_map('strval', $query->pairs());
        }
        $uid = $uidMaps[$source][$value] ?? null;
        if ($uid === null) {
            throw new DeletedReference("{$source} #{$value}");
        }

        return '@uid:' . $uid;
    }

    /**
     * @return array{0: int, 1: string[]}
     */
    private function exportAssetFiles(\ZipArchive $zip, array $ids): array
    {
        // An empty id() makes Craft abort the query (QueryAbortedException
        // from each()) rather than return nothing - e.g. building on a site
        // with no content.
        if (!$ids) {
            return [0, []];
        }

        $tempDir = Craft::getAlias('@storage') . '/runtime/site7-studio/site-kit-assets/' . uniqid();
        FileHelper::createDirectory($tempDir);
        $tempFiles = [];

        foreach (Asset::find()->id($ids)->status(null)->each(50) as $asset) {
            /** @var Asset $asset */
            try {
                $stream = $asset->getStream();
            } catch (\Throwable $e) {
                Craft::warning("Site kit: skipped the file of asset #{$asset->id}: " . $e->getMessage(), 'site7-studio');
                continue;
            }
            $temp = "{$tempDir}/{$asset->id}";
            $out = fopen($temp, 'wb');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $zip->addFile($temp, "content/assets/{$asset->id}");
            $tempFiles[] = $temp;
        }

        return [count($tempFiles), $tempFiles];
    }

    // -------------------------------------------------------------- import

    /**
     * Imports content/ from an extracted kit. Run in a process that loaded
     * the kit's project config (the installer runs it as a subprocess).
     *
     * @return array<string, int> rows imported per table
     * @throws \Exception
     */
    public function import(string $kitDir): array
    {
        $db = Craft::$app->getDb();
        if ($db->getIsPgsql()) {
            throw new \Exception('Importing content is supported on MySQL only.');
        }
        $contentDir = "{$kitDir}/content";
        if (!is_dir($contentDir)) {
            return [];
        }

        $adminId = (int)User::find()->admin(true)->status(null)->ids()[0];
        $uidToId = [];
        $resolveUid = function(string $table, string $uid) use (&$uidToId): int {
            // pairs() keys by the first column: uid => id
            $uidToId[$table] ??= array_map('intval', (new Query())->select(['uid', 'id'])->from("{{%{$table}}}")->pairs());
            if (!isset($uidToId[$table][$uid])) {
                throw new \Exception("{$table} with UID {$uid} doesn't exist on this site - was the kit's project config applied?");
            }
            return $uidToId[$table][$uid];
        };

        $transaction = $db->beginTransaction();
        $counts = [];
        try {
            // Craft creates an entry for every Single when its section is
            // saved; the kit's own entries replace them.
            $autoCreated = (new Query())->select(['id'])->from('{{%elements}}')->where(['type' => Entry::class])->column();
            $db->createCommand()->delete('{{%elements}}', ['id' => $autoCreated])->execute();
            $db->createCommand()->delete('{{%structureelements}}')->execute();

            $folderMap = $this->importFolders(json_decode((string)file_get_contents("{$contentDir}/folders.json"), true) ?: [], $resolveUid);

            $incomingIds = array_column(json_decode((string)file_get_contents("{$contentDir}/tables/elements.json"), true) ?: [], 'id');
            $clashes = (new Query())->select(['id'])->from('{{%elements}}')->where(['id' => $incomingIds])->column();
            if ($clashes) {
                throw new \Exception('Element IDs already used on this site: ' . implode(', ', array_slice($clashes, 0, 10)));
            }

            $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();

            $resolve = function(?string $value) use ($resolveUid, $adminId, $folderMap) {
                if ($value === null) {
                    return null;
                }
                if ($value === '@user') {
                    return $adminId;
                }
                if (str_starts_with($value, '@folder:')) {
                    return $folderMap[(int)substr($value, 8)] ?? null;
                }
                return $value;
            };

            foreach (self::CORE_TABLES as $table => $rules) {
                $file = "{$contentDir}/tables/{$table}.json";
                if (!is_file($file) || !$db->tableExists("{{%{$table}}}")) {
                    continue;
                }
                $counts[$table] = $this->insertRows($table, json_decode((string)file_get_contents($file), true) ?: [], array_keys($rules['structural']), $resolve, $resolveUid);
            }

            foreach (self::PLUGIN_TABLES as $table => $structural) {
                $file = "{$contentDir}/plugin-tables/{$table}.json";
                if (!is_file($file) || !$db->tableExists("{{%{$table}}}")) {
                    continue;
                }
                $db->createCommand()->delete("{{%{$table}}}")->execute();
                $counts[$table] = $this->insertRows($table, json_decode((string)file_get_contents($file), true) ?: [], array_keys($structural), $resolve, $resolveUid);
            }

            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
            throw $e;
        }

        $counts['assetFiles'] = $this->importAssetFiles("{$contentDir}/assets");
        Craft::$app->getElements()->invalidateAllCaches();

        return $counts;
    }

    /**
     * Root folders already exist (Craft creates one per volume); nested
     * ones are created with the source's UID.
     *
     * @return array<int, int> source folder ID => target folder ID
     */
    private function importFolders(array $folders, callable $resolveUid): array
    {
        $db = Craft::$app->getDb();
        $map = [];
        $pending = $folders;

        while ($pending) {
            $progress = false;
            foreach ($pending as $key => $folder) {
                if ($folder['volumeId'] === null) {
                    unset($pending[$key]);
                    $progress = true;
                    continue;
                }
                $volumeId = $resolveUid('volumes', substr($folder['volumeId'], 5));
                if ($folder['parentId'] === null) {
                    $map[(int)$folder['id']] = (int)(new Query())->select(['id'])->from('{{%volumefolders}}')->where(['volumeId' => $volumeId, 'parentId' => null])->scalar();
                } elseif (isset($map[(int)$folder['parentId']])) {
                    $existing = (new Query())->select(['id'])->from('{{%volumefolders}}')->where(['uid' => $folder['uid']])->scalar();
                    if (!$existing) {
                        $db->createCommand()->insert('{{%volumefolders}}', [
                            'parentId' => $map[(int)$folder['parentId']],
                            'volumeId' => $volumeId,
                            'name' => $folder['name'],
                            'path' => $folder['path'],
                            'uid' => $folder['uid'],
                        ])->execute();
                        $existing = $db->getLastInsertID();
                    }
                    $map[(int)$folder['id']] = (int)$existing;
                } else {
                    continue;
                }
                unset($pending[$key]);
                $progress = true;
            }
            if (!$progress) {
                throw new \Exception('Volume folders reference parents that are not in the kit.');
            }
        }

        return $map;
    }

    private function insertRows(string $table, array $rows, array $portableColumns, callable $resolve, callable $resolveUid): int
    {
        if (!$rows) {
            return 0;
        }

        $generated = (new Query())
            ->select(['column_name'])
            ->from('information_schema.columns')
            ->where(['table_schema' => Craft::$app->getDb()->createCommand('SELECT DATABASE()')->queryScalar(), 'table_name' => Craft::$app->getDb()->getSchema()->getRawTableName("{{%{$table}}}")])
            ->andWhere(['like', 'extra', 'GENERATED'])
            ->column();
        $columns = array_values(array_diff(array_keys($rows[0]), $generated));

        // JSON columns (elements_sites.content holds every field value) are
        // read back as JSON strings; Yii JSON-encodes whatever it inserts
        // into them, so pass the decoded value or it's stored double-encoded
        // and Craft sees every field as empty.
        $jsonColumns = [];
        foreach (Craft::$app->getDb()->getTableSchema("{{%{$table}}}", true)?->columns ?? [] as $name => $schema) {
            if ($schema->dbType === 'json' || $schema->type === 'json') {
                $jsonColumns[] = $name;
            }
        }

        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $this->targetSites ??= array_map('intval', (new Query())->select(['uid', 'id'])->from('{{%sites}}')->pairs());
                foreach ($row as $column => $value) {
                    if (is_string($value) && str_contains($value, '@{site:')) {
                        $row[$column] = SiteKitFiles::resolveSiteRefs($value, $this->targetSites);
                    }
                }
                foreach ($jsonColumns as $column) {
                    if (is_string($row[$column] ?? null)) {
                        // objects, not arrays: an empty {} must stay {}
                        $row[$column] = json_decode($row[$column]);
                    }
                }
                foreach ($portableColumns as $column) {
                    $value = $row[$column] ?? null;
                    $row[$column] = is_string($value) && str_starts_with($value, '@uid:')
                        ? $resolveUid(self::sourceTable($table, $column), substr($value, 5))
                        : $resolve($value);
                }
                $values[] = array_map(fn($column) => $row[$column], $columns);
            }
            Craft::$app->getDb()->createCommand()->batchInsert("{{%{$table}}}", $columns, $values)->execute();
        }

        return count($rows);
    }

    private static function sourceTable(string $table, string $column): string
    {
        return self::CORE_TABLES[$table]['structural'][$column] ?? self::PLUGIN_TABLES[$table][$column];
    }

    private function importAssetFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $written = 0;
        foreach (Asset::find()->status(null)->each(50) as $asset) {
            /** @var Asset $asset */
            $file = "{$dir}/{$asset->id}";
            if (!is_file($file)) {
                continue;
            }
            $volume = $asset->getVolume();
            $stream = fopen($file, 'rb');
            $volume->getFs()->writeFileFromStream($volume->getSubpath() . $asset->getPath(), $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $written++;
        }

        return $written;
    }
}
