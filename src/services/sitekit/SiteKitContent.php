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

    /**
     * Library content (Theme, Template packages) keeps the dev site's row
     * IDs, which stay below this; a site set up from a Theme creates its
     * own rows from here up, so the two never collide (docs/50).
     */
    public const LIBRARY_ID_LIMIT = 10_000_000;

    /** Relations between an exported element and a live one outside the export (subset exports). */
    public const LINKS_FILE = 'content/links.json';

    // -------------------------------------------------------------- export

    /**
     * Adds content/ to an open kit zip.
     *
     * @return array{counts: array<string, int>, assetFiles: int, tempFiles: string[]}
     *   tempFiles must be deleted after the zip is closed.
     */
    /**
     * @param string[]|null $sectionUids only these sections' entries, with
     *   their nested entries and the assets/categories/tags they relate to
     *   (a Theme's settings singles); null = all live content
     * @param bool|string[] $pluginTables true = all PLUGIN_TABLES, false = none,
     *   or a list (a Theme takes the forms but not the menus, which point at pages)
     * @param int[]|null $entryIds like $sectionUids, but these entries (a
     *   Template package's page)
     */
    public function export(\ZipArchive $zip, ?array $sectionUids = null, bool|array $pluginTables = true, ?array $entryIds = null): array
    {
        $db = Craft::$app->getDb();
        if ($db->getIsPgsql()) {
            throw new \Exception('Exporting content is supported on MySQL only.');
        }

        $liveIds = $this->liveElementIds();
        $ids = $liveIds;
        if ($sectionUids !== null || $entryIds !== null) {
            $sectionIds = $sectionUids ? (new Query())->select(['id'])->from('{{%sections}}')->where(['uid' => $sectionUids])->column() : [];
            $roots = array_merge(
                $sectionIds ? (new Query())->select(['id'])->from('{{%entries}}')->where(['sectionId' => $sectionIds])->column() : [],
                $entryIds ?? []
            );
            $ids = $this->subset($liveIds, $roots);
        }
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

        if (count($ids) < count($liveIds)) {
            $links = $this->links($idSet, array_flip($liveIds), $uidMaps);
            $zip->addFromString(self::LINKS_FILE, json_encode($links, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $counts['links'] = count($links);
        }

        $exportTables = match (true) {
            $pluginTables === true => self::PLUGIN_TABLES,
            $pluginTables === false => [],
            default => array_intersect_key(self::PLUGIN_TABLES, array_flip($pluginTables)),
        };
        foreach ($exportTables as $table => $structural) {
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
                foreach ((new Query())->select(['id', 'primaryOwnerId', 'fieldId'])->from("{{%{$table}}}")->where(['not', ['primaryOwnerId' => null]])->all() as $row) {
                    $owned[(int)$row['id']] = [(int)$row['primaryOwnerId'], (int)$row['fieldId']];
                }
            }
        }

        // A nested entry is only live content while its field is still on
        // its owner's field layout - one left behind in a field that was
        // removed from the layout is invisible everywhere (rp-craft: a Theme
        // Settings block in the retired `themeSetup` field), and that field
        // or its entry types may no longer travel with the structure.
        $ownerLayouts = (new Query())->select(['id', 'fieldLayoutId'])->from('{{%elements}}')
            ->where(['id' => array_unique(array_column($owned, 0))])->pairs();
        $layoutFieldIds = [];
        $fieldsService = Craft::$app->getFields();
        foreach ($owned as $id => [$ownerId, $fieldId]) {
            $layoutId = $ownerLayouts[$ownerId] ?? null;
            if ($layoutId === null || !isset($set[$id])) {
                continue;
            }
            $layoutFieldIds[$layoutId] ??= array_flip(array_map(
                fn($field) => (int)$field->id,
                $fieldsService->getLayoutById((int)$layoutId)?->getCustomFields() ?? []
            ));
            if (!isset($layoutFieldIds[$layoutId][$fieldId])) {
                unset($set[$id]);
            }
        }

        do {
            $dropped = 0;
            foreach ($owned as $id => [$ownerId]) {
                if (isset($set[$id]) && !isset($set[$ownerId])) {
                    unset($set[$id]);
                    $dropped++;
                }
            }
        } while ($dropped > 0);

        return array_keys($set);
    }

    /**
     * The given live entries, plus - repeatedly, until nothing new is found -
     * entries nested in what's included, and the assets, categories and tags
     * it relates to. Relations to other entries (pages) are not followed;
     * they travel as links().
     *
     * @return int[]
     */
    private function subset(array $liveIds, array $rootIds): array
    {
        $live = array_flip($liveIds);
        $set = array_flip(array_filter($rootIds, fn($id) => isset($live[$id])));

        do {
            $ids = array_keys($set);
            $nested = (new Query())->select(['id'])->from('{{%entries}}')->where(['primaryOwnerId' => $ids])->column();
            $related = (new Query())
                ->select(['r.targetId'])
                ->from(['r' => '{{%relations}}'])
                ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[r.targetId]]')
                ->where(['r.sourceId' => $ids, 'e.type' => [\craft\elements\Asset::class, \craft\elements\Category::class, \craft\elements\Tag::class]])
                ->column();
            $before = count($set);
            foreach (array_merge($nested, $related) as $id) {
                if (isset($live[$id])) {
                    $set[$id] = true;
                }
            }
        } while (count($set) > $before);

        return array_map('intval', array_keys($set));
    }

    /**
     * Relations with one end in the export and the other a live element
     * outside it (a page linking to another page), with both ends' UIDs.
     * Both packages carry the row; whichever installs second adds it.
     */
    private function links(array $idSet, array $liveSet, array &$uidMaps): array
    {
        $ids = array_keys($idSet);
        $rows = (new Query())->from('{{%relations}}')->where(['or', ['sourceId' => $ids], ['targetId' => $ids]])->all();
        $links = [];
        foreach ($rows as $row) {
            if (!self::isLink($row, $idSet, $liveSet)) {
                continue;
            }
            try {
                foreach (self::CORE_TABLES['relations']['structural'] as $column => $source) {
                    $row[$column] = $this->toPortable($row[$column] ?? null, $source, $uidMaps);
                }
            } catch (DeletedReference) {
                continue;
            }
            $links[] = $row;
        }
        $elementUids = (new Query())->select(['id', 'uid'])->from('{{%elements}}')
            ->where(['id' => array_merge(array_column($links, 'sourceId'), array_column($links, 'targetId'))])->pairs();

        return array_map(fn($row) => [
            'sourceUid' => $elementUids[$row['sourceId']],
            'targetUid' => $elementUids[$row['targetId']],
            'row' => $row,
        ], $links);
    }

    /**
     * Exports content into $dir/content (the layout import() reads), via a
     * temporary zip.
     *
     * @return array{counts: array, skipped: array, assetFiles: int}
     */
    public function exportToDir(string $dir, ?array $sectionUids = null, bool|array $pluginTables = true, ?array $entryIds = null): array
    {
        $zipPath = Craft::$app->getRuntimePath() . '/site7-content-' . \craft\helpers\StringHelper::randomString(8) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $result = $this->export($zip, $sectionUids, $pluginTables, $entryIds);
        $zip->close();
        foreach ($result['tempFiles'] as $tempFile) {
            @unlink($tempFile);
        }
        $zip->open($zipPath);
        $zip->extractTo($dir);
        $zip->close();
        @unlink($zipPath);

        return ['counts' => $result['counts'], 'skipped' => $result['skipped'], 'assetFiles' => $result['assetFiles']];
    }

    /** Columns that differ between sites or change by themselves, left out of signatures(). */
    private const VOLATILE_COLUMNS = ['dateCreated', 'dateUpdated', 'dateLastMerged', 'folderId', 'siteId', 'sourceSiteId'];

    /**
     * One hash per element of a content directory, over its rows in every
     * core table except structure nodes (tree positions shift as a site adds
     * entries). Comparable between the dev site's export and the same
     * content exported from a site it was installed on: site IDs and site
     * references in text are normalised (a Theme maps the source site to
     * the target's primary site), folder IDs and timestamps are left out.
     *
     * @return array<int, string> element ID => hash
     */
    public static function signatures(string $contentDir): array
    {
        $rowsByElement = [];
        foreach (self::CORE_TABLES as $table => $rules) {
            if ($table === 'structureelements') {
                continue;
            }
            $file = "{$contentDir}/tables/{$table}.json";
            foreach (is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [] as $row) {
                $elementId = (int)$row[$rules['filter'][0]];
                unset($row['id']);
                foreach (self::VOLATILE_COLUMNS as $column) {
                    unset($row[$column]);
                }
                foreach ($row as $column => $value) {
                    if (is_string($value) && str_contains($value, '@{site:')) {
                        $value = preg_replace('/@\{site:[0-9a-f\-]{36}\}/', '@{site}', $value);
                    }
                    // JSON text (elements_sites.content): MySQL's JSON
                    // column stores its own formatting and key order, so
                    // compare the value, not the text.
                    if (is_string($value) && ($value[0] ?? '') === '{' && is_array($decoded = json_decode($value, true))) {
                        $value = self::canonicalJson($decoded);
                    }
                    $row[$column] = $value;
                }
                ksort($row);
                $rowsByElement[$elementId][$table][] = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $signatures = [];
        foreach ($rowsByElement as $elementId => $tables) {
            ksort($tables);
            foreach ($tables as &$rows) {
                sort($rows);
            }
            unset($rows);
            $signatures[$elementId] = md5(json_encode($tables));
        }

        return $signatures;
    }

    private static function canonicalJson(array $data): string
    {
        $sort = function(array &$array) use (&$sort): void {
            if (!array_is_list($array)) {
                ksort($array);
            }
            foreach ($array as &$value) {
                if (is_array($value)) {
                    $sort($value);
                }
            }
        };
        $sort($data);

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** One end in the export, the other live content outside it. */
    public static function isLink(array $relation, array $idSet, array $liveSet): bool
    {
        $inside = isset($idSet[$relation['sourceId']]) + isset($idSet[$relation['targetId']]);

        return $inside === 1 && isset($liveSet[$relation['sourceId']], $liveSet[$relation['targetId']]);
    }

    /**
     * Moves this site's own new rows (elements, element sites, relations,
     * structure nodes) above LIBRARY_ID_LIMIT. Run when a Theme sets up a
     * fresh site, before anything creates elements.
     */
    public static function reserveLibraryIds(): void
    {
        $db = Craft::$app->getDb();
        foreach (array_keys(self::CORE_TABLES) as $table) {
            $next = self::nextId($table);
            if ($next !== null && $next < self::LIBRARY_ID_LIMIT) {
                $db->createCommand('ALTER TABLE ' . $db->quoteTableName("{{%{$table}}}") . ' AUTO_INCREMENT = ' . self::LIBRARY_ID_LIMIT)->execute();
            }
        }
    }

    public static function libraryIdsReserved(): bool
    {
        $next = self::nextId('elements');

        return $next !== null && $next >= self::LIBRARY_ID_LIMIT;
    }

    /** The table's next AUTO_INCREMENT value; null if it has none. */
    private static function nextId(string $table): ?int
    {
        $db = Craft::$app->getDb();
        try {
            // MySQL 8 caches table stats (AUTO_INCREMENT) for a day by default.
            $db->createCommand('SET SESSION information_schema_stats_expiry = 0')->execute();
        } catch (\Throwable) {
            // MariaDB: no such cache
        }
        $value = (new Query())->select(['AUTO_INCREMENT'])->from('information_schema.tables')
            ->where(['table_schema' => $db->createCommand('SELECT DATABASE()')->queryScalar(), 'table_name' => $db->getSchema()->getRawTableName("{{%{$table}}}")])
            ->scalar();

        return $value === null || $value === false ? null : (int)$value;
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
     * @param int[]|null $onlyIds Library updates (docs/53): import only these
     *   elements' rows (no plugin tables)
     * @param int[] $replaceIds elements already here whose rows are replaced
     *   by the incoming ones (their structure nodes are kept)
     * @return array<string, int> rows imported per table
     * @throws \Exception
     */
    public function import(string $kitDir, ?array $onlyIds = null, array $replaceIds = []): array
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
        $primarySiteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $uidToId = [];
        $resolveUid = function(string $table, string $uid) use (&$uidToId, $primarySiteId): int {
            // pairs() keys by the first column: uid => id
            $uidToId[$table] ??= array_map('intval', (new Query())->select(['uid', 'id'])->from("{{%{$table}}}")->pairs());
            if (!isset($uidToId[$table][$uid])) {
                // A Theme installs into this site rather than replacing it,
                // so content from the source's site lands on the primary site.
                if ($table === 'sites') {
                    return $primarySiteId;
                }
                throw new \Exception("{$table} with UID {$uid} doesn't exist on this site - was the kit's project config applied?");
            }
            return $uidToId[$table][$uid];
        };

        $rowsByTable = [];
        foreach (array_keys(self::CORE_TABLES) as $table) {
            $file = "{$contentDir}/tables/{$table}.json";
            $rowsByTable[$table] = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        }
        $replaceSet = array_flip($replaceIds);
        if ($onlyIds !== null) {
            $onlySet = array_flip($onlyIds);
            foreach (self::CORE_TABLES as $table => $rules) {
                $key = $rules['filter'][0];
                $rowsByTable[$table] = array_values(array_filter($rowsByTable[$table], fn($row) => $table === 'structureelements'
                    ? ($row['elementId'] === null || (isset($onlySet[$row['elementId']]) && !isset($replaceSet[$row['elementId']])))
                    : isset($onlySet[$row[$key]])));
            }
        }

        // Content can arrive in parts (a Theme's settings, then a Starter
        // Kit's pages). An element already here with the same UID came with
        // an earlier part (e.g. a logo both use): skip it. The same ID with
        // a different UID is a real clash.
        $incomingUids = array_column($rowsByTable['elements'], 'uid', 'id');
        $skipIds = [];
        $clashes = [];
        foreach ((new Query())->select(['id', 'uid'])->from('{{%elements}}')->where(['id' => array_keys($incomingUids)])->pairs() as $id => $uid) {
            if ($incomingUids[$id] === $uid) {
                if (!isset($replaceSet[$id])) {
                    $skipIds[$id] = true;
                }
            } else {
                $clashes[] = $id;
            }
        }
        if ($clashes) {
            throw new \Exception('Element IDs already used on this site by other content: ' . implode(', ', array_slice($clashes, 0, 10)));
        }

        // Structure nodes keep the source's tree positions, so they can only
        // join a tree that came from the same source (same root node).
        foreach ($rowsByTable['structureelements'] as $row) {
            if ($row['elementId'] !== null || !is_string($row['structureId'])) {
                continue;
            }
            $structureId = $resolveUid('structures', substr($row['structureId'], 5));
            $rootUid = (new Query())->select(['uid'])->from('{{%structureelements}}')->where(['structureId' => $structureId, 'elementId' => null])->scalar();
            if ($rootUid !== false && $rootUid !== $row['uid']) {
                throw new \Exception("Structure #{$structureId} already has entries created on this site; its tree can't take the incoming entries' positions.");
            }
        }

        $transaction = $db->beginTransaction();
        $counts = [];
        try {
            // Craft creates an entry for every Single when its section is
            // saved; an incoming entry for that Single replaces it.
            $singleSectionUids = [];
            foreach ($rowsByTable['entries'] as $row) {
                if (!isset($skipIds[$row['id']]) && is_string($row['sectionId'] ?? null) && str_starts_with($row['sectionId'], '@uid:')) {
                    $singleSectionUids[substr($row['sectionId'], 5)] = true;
                }
            }
            $singleSectionIds = (new Query())->select(['id'])->from('{{%sections}}')->where(['uid' => array_keys($singleSectionUids), 'type' => 'single'])->column();
            $replaced = (new Query())->select(['id'])->from('{{%entries}}')
                ->where(['sectionId' => $singleSectionIds])
                ->andWhere(['not', ['id' => array_keys($incomingUids)]])
                ->column();
            $db->createCommand()->delete('{{%elements}}', ['id' => $replaced])->execute();

            $folderMap = $this->importFolders(json_decode((string)file_get_contents("{$contentDir}/folders.json"), true) ?: [], $resolveUid);

            $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();

            // Replaced elements: their own rows go (foreign key checks are
            // off, so nothing cascades to the entries nested in them or to
            // relations pointing at them); the incoming rows go in below.
            if ($replaceIds) {
                foreach (self::CORE_TABLES as $table => $rules) {
                    if ($table !== 'structureelements' && $db->tableExists("{{%{$table}}}")) {
                        $db->createCommand()->delete("{{%{$table}}}", [$rules['filter'][0] => $replaceIds])->execute();
                    }
                }
            }

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
                if (!$rowsByTable[$table] || !$db->tableExists("{{%{$table}}}")) {
                    continue;
                }
                $rows = $this->withoutExisting($table, $rowsByTable[$table], $rules['filter'][0], $skipIds);
                $counts[$table] = $this->insertRows($table, $rows, array_keys($rules['structural']), $resolve, $resolveUid);
            }

            $counts['links'] = $this->importLinks("{$kitDir}/" . self::LINKS_FILE, $resolve, $resolveUid);

            foreach (self::PLUGIN_TABLES as $table => $structural) {
                $file = "{$contentDir}/plugin-tables/{$table}.json";
                if ($onlyIds !== null || !is_file($file) || !$db->tableExists("{{%{$table}}}")) {
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

        $counts['assetFiles'] = $this->importAssetFiles("{$contentDir}/assets", $onlyIds);
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

    /**
     * Structure the content needs that this site doesn't have (sections,
     * entry types, fields...), checked before importing.
     *
     * @return string[] e.g. "entrytypes a0d2318d-..."
     */
    public function missingStructure(string $kitDir): array
    {
        $needed = [];
        foreach (self::CORE_TABLES as $table => $rules) {
            $file = "{$kitDir}/content/tables/{$table}.json";
            foreach (is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [] as $row) {
                foreach (array_keys($rules['structural']) as $column) {
                    $value = $row[$column] ?? null;
                    if (is_string($value) && str_starts_with($value, '@uid:')) {
                        $needed[self::sourceTable($table, $column)][substr($value, 5)] = true;
                    }
                }
            }
        }

        $missing = [];
        foreach ($needed as $source => $uids) {
            if ($source === 'sites') {
                continue; // falls back to the primary site
            }
            $query = (new Query())->select(['uid'])->from("{{%{$source}}}")->where(['uid' => array_keys($uids)]);
            if (Craft::$app->getDb()->columnExists("{{%{$source}}}", 'dateDeleted')) {
                $query->andWhere(['dateDeleted' => null]);
            }
            foreach (array_diff(array_keys($uids), $query->column()) as $uid) {
                $missing[] = "{$source} {$uid}";
            }
        }

        return $missing;
    }

    /**
     * Adds the relations of links() whose both ends are now on this site
     * (same IDs and UIDs) and that aren't here yet.
     */
    private function importLinks(string $file, callable $resolve, callable $resolveUid): int
    {
        $links = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        if (!$links) {
            return 0;
        }

        $here = (new Query())->select(['id', 'uid'])->from('{{%elements}}')
            ->where(['id' => array_merge(array_column(array_column($links, 'row'), 'sourceId'), array_column(array_column($links, 'row'), 'targetId'))])
            ->pairs();
        $rows = [];
        foreach ($links as $link) {
            $row = $link['row'];
            if (($here[$row['sourceId']] ?? null) === $link['sourceUid'] && ($here[$row['targetId']] ?? null) === $link['targetUid']) {
                $rows[] = $row;
            }
        }
        $rows = $this->withoutExisting('relations', $rows, 'sourceId', []);

        return $this->insertRows('relations', $rows, array_keys(self::CORE_TABLES['relations']['structural']), $resolve, $resolveUid);
    }

    /**
     * Drops rows belonging to elements that are already here (by the row's
     * owning element column) and rows whose UID already exists.
     */
    private function withoutExisting(string $table, array $rows, string $ownerColumn, array $skipIds): array
    {
        $rows = array_values(array_filter($rows, fn($row) => !isset($skipIds[$row[$ownerColumn] ?? null])));
        if ($rows && array_key_exists('uid', $rows[0])) {
            $existing = array_flip((new Query())->select(['uid'])->from("{{%{$table}}}")->where(['uid' => array_column($rows, 'uid')])->column());
            $rows = array_values(array_filter($rows, fn($row) => !isset($existing[$row['uid']])));
        }

        return $rows;
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
                        // A site UID unknown here is the source site of a
                        // Theme-style install: map it to the primary site.
                        preg_match_all('/@\{site:([0-9a-f\-]{36})\}/', $value, $m);
                        foreach ($m[1] as $uid) {
                            $this->targetSites[$uid] ??= (int)Craft::$app->getSites()->getPrimarySite()->id;
                        }
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

    private function importAssetFiles(string $dir, ?array $onlyIds = null): int
    {
        if (!is_dir($dir) || $onlyIds === []) {
            return 0;
        }

        $written = 0;
        $query = Asset::find()->status(null);
        if ($onlyIds !== null) {
            $query->id($onlyIds);
        }
        foreach ($query->each(50) as $asset) {
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
