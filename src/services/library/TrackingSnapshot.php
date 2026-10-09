<?php

namespace site7\studio\services\library;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Matrix;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use site7\studio\models\Settings;
use site7\studio\records\PackageRecord;
use site7\studio\Site7Studio;

/**
 * What this site's Site7 Studio tracked, kept across a plugin uninstall
 * (docs/58_PLUGIN_UNINSTALL_AND_REINSTALL.md).
 *
 * Uninstalling drops every plugin table (Install::safeDown()) while the site
 * itself - fields, entry types, the page builder, entries, _blocks
 * templates - stays. write() runs from Site7Studio::beforeUninstall(), before
 * the tables go, and saves their rows to storage/site7-studio/FILE.
 * restore() runs once the plugin is installed again and puts them back:
 * new primary keys, packageId/sharedResourceId remapped, Craft references
 * checked by UID (handle as a fallback), rows whose resource is gone
 * skipped and reported.
 *
 * Never in the file: entitlements and licence data (they come back only
 * from Commerce24), Commerce settings, and install/sync sessions (transient).
 *
 * Restore only fills in - a package whose status isn't 'available' (the
 * status discovery gives a new row) keeps it, and a row that already exists
 * is skipped - so running it on a site that has tracking data never rolls
 * that data back.
 */
class TrackingSnapshot extends Component
{
    public const SCHEMA_VERSION = 1;

    /** The snapshot waiting to be restored, in self::directory(). */
    public const FILE = 'uninstall-snapshot.json';

    private const SETTINGS_PATH = 'plugins.site7-studio.settings';

    /**
     * Tracked tables, parents first. For each: the foreign keys to remap
     * (column => parent table) and the column sets that identify a row
     * already present (a unique index, or a natural key where there's none).
     */
    public const TABLES = [
        'site7_packages' => ['fks' => [], 'unique' => [['handle']]],
        'site7_package_versions' => ['fks' => ['packageId' => 'site7_packages'], 'unique' => [['packageId', 'version']]],
        'site7_package_dependencies' => ['fks' => ['packageId' => 'site7_packages'], 'unique' => [['packageId', 'dependencyType', 'dependencyHandle']]],
        'site7_package_publications' => ['fks' => ['packageId' => 'site7_packages'], 'unique' => [['uid']]],
        'site7_components' => ['fks' => ['packageId' => 'site7_packages'], 'unique' => [['packageId']]],
        'site7_templates' => ['fks' => ['packageId' => 'site7_packages'], 'unique' => [['packageId']]],
        'site7_installed_files' => ['fks' => ['packageId' => 'site7_packages'], 'unique' => [['packageId', 'targetPath']]],
        'site7_section_import_sources' => ['fks' => ['packageId' => 'site7_packages'], 'unique' => [['packageId'], ['sourceUid']]],
        'site7_page_import_sources' => ['fks' => ['packageId' => 'site7_packages'], 'unique' => [['packageId'], ['sourceUid']]],
        'site7_website_import_sources' => ['fks' => ['packageId' => 'site7_packages'], 'unique' => [['packageId'], ['selectionKey']]],
        'site7_shared_resources' => ['fks' => [], 'unique' => [['handle']]],
        'site7_shared_resource_dependencies' => ['fks' => ['sharedResourceId' => 'site7_shared_resources'], 'unique' => [['sharedResourceId', 'dependsOnHandle']]],
        'site7_installed_starter_kits' => ['fks' => [], 'unique' => [['handle']]],
        'site7_sync_history' => ['fks' => [], 'unique' => [['handle', 'fromVersion', 'toVersion', 'dateCreated']]],
    ];

    /** Transient state, never snapshotted. */
    public const EXCLUDED_TABLES = ['site7_install_sessions', 'site7_sync_sessions'];

    /**
     * site7_packages columns that are licence data: Commerce24's grace
     * period, and what a signed archive said - a local file must not be able
     * to set either.
     */
    public const EXCLUDED_PACKAGE_COLUMNS = ['entitlementRemovableOn', 'verifiedPricingType', 'signatureStatus', 'signatureKeyId'];

    /** site7_packages columns discovery reads from manifest.json: the Library wins. */
    private const MANIFEST_COLUMNS = ['name', 'handle', 'type', 'version', 'author', 'description', 'category', 'tags'];

    /** Statuses that mean "on this site". */
    private const ON_SITE = ['installed', 'enabled', 'disabled'];

    public static function directory(): string
    {
        return Craft::getAlias('@storage') . '/site7-studio';
    }

    public static function path(): string
    {
        return self::directory() . '/' . self::FILE;
    }

    /**
     * The settings a snapshot keeps: everything but Commerce24's (endpoint,
     * key, store, the fallback package...), which stays as configured.
     */
    public static function filterSettings(array $settings): array
    {
        return array_filter(
            $settings,
            fn($key) => !str_starts_with((string)$key, 'commerce') && $key !== 'defaultPackage',
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * The status a restored Section package gets, given how much of it this
     * site still has: none of its block types → 'available' (install it
     * again), not on the page builder any more → 'installed' (Enable links
     * it). $linked is null when there's no page builder to check.
     */
    public static function checkedSectionStatus(string $status, int $blocks, int $blocksOnSite, ?bool $linked): string
    {
        if (!in_array($status, self::ON_SITE, true)) {
            return $status;
        }
        if ($blocks > 0 && $blocksOnSite === 0) {
            return 'available';
        }
        if ($status === 'enabled' && $linked === false) {
            return 'installed';
        }

        return $status;
    }

    // ------------------------------------------------------------- write

    /**
     * Site7Studio::beforeUninstall(): writes the snapshot, never throws - a
     * failed snapshot is logged and the uninstall goes on.
     */
    public function writeBeforeUninstall(): ?string
    {
        try {
            $path = $this->write();
            Craft::info("Tracking snapshot written to {$path} before uninstall.", 'site7-studio');

            return $path;
        } catch (\Throwable $e) {
            Craft::warning('Could not write the tracking snapshot before uninstall: ' . $e->getMessage(), 'site7-studio');

            return null;
        }
    }

    /**
     * Writes the snapshot to self::path(). A snapshot already there (never
     * restored) is kept, renamed uninstall-snapshot.superseded-<time>.json.
     */
    public function write(): string
    {
        $payload = $this->build();
        $dir = self::directory();
        FileHelper::createDirectory($dir);
        $path = self::path();
        if (is_file($path)) {
            rename($path, "{$dir}/uninstall-snapshot.superseded-" . date('Ymd-His') . '.json');
        }
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        file_put_contents("{$path}.part", $json, LOCK_EX);
        rename("{$path}.part", $path);

        return $path;
    }

    /** The snapshot's contents. */
    public function build(): array
    {
        $plugin = Site7Studio::getInstance();
        $db = Craft::$app->getDb();

        $tables = [];
        foreach (array_keys(self::TABLES) as $table) {
            if (!$db->tableExists("{{%{$table}}}")) {
                continue;
            }
            $rows = (new Query())->from("{{%{$table}}}")->orderBy(['id' => SORT_ASC])->all();
            if ($table === 'site7_packages') {
                $rows = array_map(fn(array $row) => array_diff_key($row, array_flip(self::EXCLUDED_PACKAGE_COLUMNS)), $rows);
            }
            $tables[$table] = $rows;
        }

        $settings = Craft::$app->getProjectConfig()->get(self::SETTINGS_PATH) ?? [];
        $matrixField = !empty($settings['matrixFieldUid']) ? Craft::$app->getFields()->getFieldByUid($settings['matrixFieldUid']) : null;

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'plugin' => [
                'handle' => $plugin->handle,
                'version' => $plugin->getVersion(),
                'schemaVersion' => $plugin->schemaVersion,
            ],
            'createdAt' => date('c'),
            'craftVersion' => Craft::$app->getVersion(),
            'libraryPath' => (string)Craft::getAlias('@packages'),
            'settings' => self::filterSettings($settings),
            'matrixField' => $matrixField ? ['uid' => $matrixField->uid, 'id' => $matrixField->id, 'handle' => $matrixField->handle] : null,
            'excluded' => [
                'tables' => self::EXCLUDED_TABLES,
                'packageColumns' => self::EXCLUDED_PACKAGE_COLUMNS,
                'settings' => 'commerce*, defaultPackage',
            ],
            'tables' => $tables,
        ];
    }

    // ------------------------------------------------------------- restore

    /**
     * After the plugin is installed (Site7Studio::afterInstall() hooks
     * Plugins::EVENT_AFTER_INSTALL_PLUGIN, after Craft's install transaction
     * committed): restore a snapshot if there is one, otherwise reconcile
     * the Library with what the site already has. Never throws.
     */
    public function restoreAfterInstall(): void
    {
        try {
            if (is_file(self::path())) {
                $report = $this->restore(false);
                $message = $report['error'] === null
                    ? sprintf('Site7 Studio restored the tracking of %d packages from its uninstall snapshot.', count($report['packages']['restored']))
                    : 'Site7 Studio could not restore its uninstall snapshot (it was left in storage/site7-studio): ' . $report['error'];
            } else {
                $report = (new LibraryReconciler())->reconcile(false);
                $message = $report['changes'] === 0 ? null
                    : sprintf('Site7 Studio found %d Library packages already on this site and marked them installed.', $report['changes']);
            }
            Craft::info('After install: ' . json_encode($report), 'site7-studio');
            if ($message !== null && !Craft::$app->getRequest()->getIsConsoleRequest()) {
                Craft::$app->getSession()->setNotice($message);
            }
        } catch (\Throwable $e) {
            Craft::warning('Restoring tracking after install failed: ' . $e->getMessage(), 'site7-studio');
        }
    }

    /**
     * Restores self::path() (or $path). $dryRun runs the whole restore in a
     * transaction that's rolled back, and saves no settings: the report
     * says what a real run would do. A real run that succeeds renames the
     * file uninstall-snapshot.restored-<time>.json; one that fails rolls
     * back, logs, and leaves the file in place.
     *
     * @return array{error: ?string, dryRun: bool, settings: array, packages: array{restored: array<string, string>, kept: array<string, string>, skipped: array<string, string>}, rows: array<string, array{restored: int, skipped: int}>, flags: string[], renamedTo: ?string}
     */
    public function restore(bool $dryRun = true, ?string $path = null): array
    {
        $path ??= self::path();
        $report = ['error' => null, 'dryRun' => $dryRun, 'settings' => [], 'packages' => ['restored' => [], 'kept' => [], 'skipped' => []], 'rows' => [], 'flags' => [], 'renamedTo' => null];

        $snapshot = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (!is_array($snapshot) || !isset($snapshot['tables']) || (int)($snapshot['schemaVersion'] ?? 0) > self::SCHEMA_VERSION) {
            $report['error'] = is_file($path) ? "{$path} is not a snapshot this version can read." : "No snapshot at {$path}.";

            return $report;
        }

        try {
            // Project config, not a DB row: saved before the transaction, only
            // fills settings that are empty, so a retry after a failure is safe.
            $matrixField = $this->restoreSettings($snapshot, $dryRun, $report);
        } catch (\Throwable $e) {
            $report['flags'][] = 'Settings were not restored: ' . $e->getMessage();
            $matrixFieldId = Site7Studio::getInstance()->getSettings()->getMatrixFieldId();
            $matrixField = $matrixFieldId ? Craft::$app->getFields()->getFieldById($matrixFieldId) : null;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $this->restoreTables($snapshot['tables'], $matrixField instanceof Matrix ? $matrixField : null, $report);
            if ($dryRun) {
                $transaction->rollBack();
            } else {
                $transaction->commit();
            }
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $report['error'] = $e->getMessage();
            Craft::warning("Restoring the tracking snapshot {$path} failed, it was left in place: " . $e->getMessage(), 'site7-studio');

            return $report;
        }

        if (!$dryRun && $path === self::path()) {
            $renamed = self::directory() . '/uninstall-snapshot.restored-' . date('Ymd-His') . '.json';
            if (@rename($path, $renamed)) {
                $report['renamedTo'] = $renamed;
            } else {
                $report['flags'][] = "Restored, but {$path} could not be renamed - delete it so it isn't restored again.";
            }
        }

        return $report;
    }

    /**
     * Fills the settings that are empty on this site from the snapshot, and
     * finds the page builder field: by UID, else by handle.
     *
     * @return \craft\base\FieldInterface|null the page builder field
     */
    private function restoreSettings(array $snapshot, bool $dryRun, array &$report): ?\craft\base\FieldInterface
    {
        $plugin = Site7Studio::getInstance();
        [$changes, $matrixField] = $this->settingsToRestore($snapshot, Craft::$app->getProjectConfig()->get(self::SETTINGS_PATH) ?? [], $report['flags']);

        $report['settings'] = $changes;
        if ($changes && !$dryRun) {
            if (!Craft::$app->getPlugins()->savePluginSettings($plugin, Settings::mergeWithStored($changes))) {
                throw new \Exception(implode(' ', $plugin->getSettings()->getErrorSummary(true)) ?: 'the settings did not validate.');
            }
            if (array_key_exists('libraryPath', $changes)) {
                Craft::setAlias('@packages', $plugin->getLibraryPath());
            }
        }

        return $matrixField;
    }

    /**
     * The settings restore would save over $stored (this site's stored
     * settings), and the page builder field it resolves. Saves nothing.
     *
     * @param string[] $flags gets what couldn't be resolved
     * @return array{0: array<string, mixed>, 1: ?\craft\base\FieldInterface}
     */
    public function settingsToRestore(array $snapshot, array $stored, array &$flags): array
    {
        $plugin = Site7Studio::getInstance();
        $fields = Craft::$app->getFields();
        $known = array_keys($plugin->getSettings()->getAttributes());

        $changes = [];
        foreach (self::filterSettings((array)($snapshot['settings'] ?? [])) as $key => $value) {
            if ($key !== 'matrixFieldUid' && in_array($key, $known, true)
                && (!array_key_exists($key, $stored) || $stored[$key] === null || $stored[$key] === '' || $stored[$key] === [])) {
                $changes[$key] = $value;
            }
        }

        $matrixField = !empty($stored['matrixFieldUid']) ? $fields->getFieldByUid($stored['matrixFieldUid']) : null;
        if ($matrixField === null) {
            $uid = $snapshot['matrixField']['uid'] ?? $snapshot['settings']['matrixFieldUid'] ?? null;
            $handle = $snapshot['matrixField']['handle'] ?? null;
            $matrixField = $uid ? $fields->getFieldByUid($uid) : null;
            if (!$matrixField instanceof Matrix && $handle) {
                $matrixField = $fields->getFieldByHandle($handle);
                if ($matrixField instanceof Matrix) {
                    $flags[] = "Page builder field found by its handle '{$handle}' (its UID changed).";
                }
            }
            if ($matrixField instanceof Matrix) {
                $changes['matrixFieldUid'] = $matrixField->uid;
            } else {
                $matrixField = null;
                if ($uid || $handle) {
                    $flags[] = 'The page builder field of the snapshot is not on this site any more - set it in Settings → General.';
                }
            }
        }

        return [$changes, $matrixField];
    }

    private function restoreTables(array $tables, ?Matrix $matrixField, array &$report): void
    {
        $db = Craft::$app->getDb();
        $packageManager = Site7Studio::getInstance()->packageManager;
        $packageManager->discoverPackages();
        $linked = $matrixField ? array_map(fn($entryType) => $entryType->handle, $matrixField->getEntryTypes()) : null;

        /** @var array<string, array<int, int>> parent table => old id => new id */
        $ids = ['site7_packages' => [], 'site7_shared_resources' => []];

        foreach (self::TABLES as $table => $definition) {
            $rows = (array)($tables[$table] ?? []);
            $report['rows'][$table] = ['restored' => 0, 'skipped' => 0];
            if (!$rows || !$db->tableExists("{{%{$table}}}")) {
                continue;
            }
            $columns = $db->getTableSchema("{{%{$table}}}", true)->columnNames;

            foreach ($rows as $row) {
                $oldId = (int)($row['id'] ?? 0);
                unset($row['id']);

                if ($table === 'site7_packages') {
                    $newId = $this->restorePackage($row, $columns, $linked, $report);
                    if ($newId !== null) {
                        $ids[$table][$oldId] = $newId;
                    }
                    // Kept (already tracked here) counts as skipped.
                    $report['rows'][$table]['restored'] = count($report['packages']['restored']);
                    $report['rows'][$table]['skipped'] = count($report['packages']['kept']) + count($report['packages']['skipped']);
                    continue;
                }

                // Remap foreign keys; a row whose parent wasn't restored goes too.
                foreach ($definition['fks'] as $column => $parent) {
                    $newParent = $ids[$parent][(int)($row[$column] ?? 0)] ?? null;
                    if ($newParent === null) {
                        $report['rows'][$table]['skipped']++;
                        continue 2;
                    }
                    $row[$column] = $newParent;
                }

                $row = $this->checkCraftReferences($table, $row, $report);
                if ($row === null) {
                    $report['rows'][$table]['skipped']++;
                    continue;
                }
                $row = array_intersect_key($row, array_flip($columns));

                $existing = $this->existingId($table, $row, $definition['unique']);
                if ($existing !== null) {
                    if (isset($ids[$table])) {
                        $ids[$table][$oldId] = $existing;
                    }
                    $report['rows'][$table]['skipped']++;
                    continue;
                }

                Db::insert("{{%{$table}}}", $row);
                if (isset($ids[$table])) {
                    $ids[$table][$oldId] = (int)$db->getLastInsertID();
                }
                $report['rows'][$table]['restored']++;
            }
        }
    }

    /**
     * Puts a package row's tracking back on the row discovery made for it.
     * A package no longer in the Library is skipped: its rows stay in the
     * snapshot file.
     *
     * @return int|null the package's id on this site
     */
    private function restorePackage(array $row, array $columns, ?array $linked, array &$report): ?int
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $handle = (string)($row['handle'] ?? '');
        if ($handle === '' || $packageManager->getPackagePath($handle) === null) {
            $report['packages']['skipped'][$handle] = 'not in the Library';
            return null;
        }
        $record = PackageRecord::findOne(['handle' => $handle]);
        if ($record === null) {
            $report['packages']['skipped'][$handle] = 'in the Library, but its package failed validation';
            return null;
        }
        if ($record->status !== 'available') {
            $report['packages']['kept'][$handle] = (string)$record->status;
            return (int)$record->id;
        }

        $status = (string)($row['status'] ?? 'available');
        if ($record->type === 'section' && in_array($status, self::ON_SITE, true)) {
            $blocks = $packageManager->sectionBlockHandles($handle);
            $entries = Craft::$app->getEntries();
            $onSite = array_values(array_filter($blocks, fn($block) => $entries->getEntryTypeByHandle($block) !== null));
            $checked = self::checkedSectionStatus($status, count($blocks), count($onSite), $linked === null ? null : (bool)array_intersect($onSite, $linked));
            if ($checked !== $status) {
                $report['flags'][] = "{$handle}: was {$status}, restored as {$checked} - " . ($checked === 'available' ? 'its block type is not on this site any more.' : 'its block is not on the page builder any more.');
            }
            $status = $checked;
        }

        $update = array_diff_key($row, array_flip(array_merge(self::MANIFEST_COLUMNS, self::EXCLUDED_PACKAGE_COLUMNS, ['dateUpdated'])));
        $update['status'] = $status;
        if (!empty($update['creatorId']) && !User::find()->id((int)$update['creatorId'])->status(null)->exists()) {
            $update['creatorId'] = null;
        }
        $current = (new Query())->from('{{%site7_packages}}')->where(['id' => $record->id])->one() ?: [];
        $changes = array_filter(
            array_intersect_key($update, array_flip($columns)),
            fn($value, $column) => (string)($current[$column] ?? '') !== (string)($value ?? ''),
            ARRAY_FILTER_USE_BOTH
        );
        if (!$changes) {
            $report['packages']['kept'][$handle] = "{$status} (unchanged)";
            return (int)$record->id;
        }
        Db::update('{{%site7_packages}}', $changes, ['id' => $record->id]);
        $report['packages']['restored'][$handle] = $status;

        return (int)$record->id;
    }

    /**
     * Checks a row's references to Craft (by UID, then handle). Returns the
     * row - its references updated if found by handle - or null to skip it.
     */
    private function checkCraftReferences(string $table, array $row, array &$report): ?array
    {
        switch ($table) {
            case 'site7_section_import_sources':
                $isSection = ($row['sourceType'] ?? '') === 'craft-section';
                $entries = Craft::$app->getEntries();
                $source = $isSection
                    ? ($entries->getSectionByUid((string)$row['sourceUid']) ?? $entries->getSectionByHandle((string)$row['sourceHandle']))
                    : ($entries->getEntryTypeByUid((string)$row['sourceUid']) ?? $entries->getEntryTypeByHandle((string)$row['sourceHandle']));
                if ($source === null) {
                    $report['flags'][] = "Import source '{$row['sourceHandle']}' is not on this site any more - its link was not restored.";
                    return null;
                }
                $row['sourceUid'] = $source->uid;
                return $row;

            case 'site7_page_import_sources':
                if (!Entry::find()->uid((string)$row['sourceUid'])->status(null)->site('*')->exists()) {
                    $report['flags'][] = "Imported page '{$row['sourceHandle']}' is not on this site any more - its link was not restored.";
                    return null;
                }
                return $row;

            case 'site7_website_import_sources':
                $uids = (array)json_decode((string)($row['sourceEntryUids'] ?? '[]'), true);
                $found = $uids ? Entry::find()->uid($uids)->status(null)->site('*')->unique()->count() : 0;
                if ($uids && $found === 0) {
                    $report['flags'][] = "Imported website '{$row['selectionKey']}': none of its pages are on this site any more - its link was not restored.";
                    return null;
                }
                if ($found < count($uids)) {
                    $report['flags'][] = "Imported website '{$row['selectionKey']}': " . (count($uids) - $found) . ' of its pages are not on this site any more.';
                }
                return $row;

            case 'site7_shared_resources':
                $fields = Craft::$app->getFields();
                $field = (!empty($row['craftUid']) ? $fields->getFieldByUid((string)$row['craftUid']) : null) ?? $fields->getFieldByHandle((string)$row['handle']);
                if ($field === null) {
                    $report['flags'][] = "Shared resource '{$row['handle']}' is not on this site any more - restored without its Craft field.";
                }
                $row['craftUid'] = $field?->uid;
                $row['craftId'] = $field?->id;
                return $row;

            case 'site7_installed_files':
                $root = dirname(rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/'));
                if (!is_file($root . '/' . $row['targetPath'])) {
                    $report['flags'][] = "{$row['targetPath']} is not on disk any more (its baseline was restored: updates leave it deleted).";
                }
                return $row;
        }

        return $row;
    }

    /** The id of a row like $row already in $table, by any of its unique column sets. */
    private function existingId(string $table, array $row, array $uniqueSets): ?int
    {
        foreach ($uniqueSets as $set) {
            $condition = [];
            foreach ($set as $column) {
                if (!array_key_exists($column, $row) || $row[$column] === null) {
                    continue 2;
                }
                $condition[$column] = $row[$column];
            }
            $id = (new Query())->select('id')->from("{{%{$table}}}")->where($condition)->scalar();
            if ($id !== false && $id !== null) {
                return (int)$id;
            }
        }

        return null;
    }
}
