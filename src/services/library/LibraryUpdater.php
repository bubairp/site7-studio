<?php

namespace site7\studio\services\library;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use site7\studio\records\PackageRecord;
use site7\studio\repositories\marketplace\Commerce24MarketplaceRepository;
use site7\studio\services\import\SectionSchemaService;
use site7\studio\services\PackageImportService;
use site7\studio\services\sitekit\SiteKitContent;
use site7\studio\services\starterkit\KitBuilder;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\services\synchronization\PackageUpdatePlanner;
use site7\studio\services\template\TemplateBuilder;
use site7\studio\services\theme\ThemeUpdater;
use site7\studio\Site7Studio;

/**
 * Updates installed Library packages from Commerce24 (docs/53_LIBRARY_UPDATES.md).
 *
 * The rule, for every file, structure item, content element and plugin
 * table: the version this site installed is the baseline - the package's
 * own copy in the Library, kept aside before the new version replaces it -
 * and PackageUpdatePlanner::classify() decides:
 *   unchanged here, changed upstream  -> updated
 *   changed here (the customer's edit) -> kept, reported
 *   deleted here                       -> stays deleted
 *   gone upstream                      -> content/files: removed if unchanged
 *                                         here (content to the trash);
 *                                         structure: kept
 *
 * Section, Template, Theme (ThemeUpdater) and Library Starter Kit packages.
 */
class LibraryUpdater extends Component
{
    /** Update order: structure and code first, pages next, the kit's own content last. */
    public const TYPE_ORDER = ['theme' => 0, 'section' => 1, 'template' => 2, 'starter-kit' => 3];

    /**
     * Installed Library packages that Commerce24 has a newer version of.
     *
     * @return array[] handle, type, name, from, to, releaseNotes, size, entitled, supported
     */
    public function availableUpdates(): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $catalog = (new LibraryDistribution())->catalog();
        $updates = [];
        foreach ($packageManager->getAllPackages() as $record) {
            $entry = $catalog[$record->handle] ?? null;
            $path = $packageManager->getPackagePath($record->handle);
            if (!$entry || !$path || !LibraryDistribution::isCurrentFormat($record->type, $path)
                || !version_compare((string)$entry['version'], (string)$record->version, '>')) {
                continue;
            }
            $updates[] = [
                'handle' => $record->handle,
                'type' => $record->type,
                'name' => $entry['name'] ?? $record->name,
                'from' => $record->version,
                'to' => $entry['version'],
                'releaseNotes' => $entry['metadata']['releaseNotes'] ?? null,
                'size' => (int)($entry['size'] ?? 0),
                'entitled' => $entry['entitled'] ?? true,
                'supported' => isset(self::TYPE_ORDER[$record->type]),
            ];
        }
        usort($updates, fn($a, $b) => [self::TYPE_ORDER[$a['type']] ?? 9, $a['handle']] <=> [self::TYPE_ORDER[$b['type']] ?? 9, $b['handle']]);

        return $updates;
    }

    /**
     * @param string[] $handles packages to update; [] = every supported update
     * @return array{updated: string[], skipped: string[], errors: string[], report: array<string, array>}
     */
    public function update(array $handles = [], ?callable $log = null): array
    {
        $log ??= fn(string $line) => null;
        $result = ['updated' => [], 'skipped' => [], 'errors' => [], 'report' => []];
        $updates = [];
        foreach ($this->availableUpdates() as $update) {
            if ($handles === [] || in_array($update['handle'], $handles, true)) {
                $updates[$update['handle']] = $update;
            }
        }
        foreach (array_diff($handles, array_keys($updates)) as $handle) {
            $result['skipped'][] = $handle;
            $log("{$handle}: no update available");
        }
        if (!$updates) {
            return $result;
        }

        $log('Backing up the database…');
        $log('Backup: ' . Craft::$app->getDb()->backup());

        foreach ($updates as $handle => $update) {
            if (!$update['entitled']) {
                $result['errors'][] = "{$handle}: not in your plan or purchases";
                $log("FAILED {$handle}: not in your plan or purchases");
                continue;
            }
            try {
                $report = $this->updatePackage($handle, $update, $log);
                $result['updated'][] = $handle;
                $result['report'][$handle] = $report;
                $log(sprintf('Updated %s %s -> %s: %s', $handle, $update['from'], $update['to'], self::summary($report)));
                foreach ($report['kept'] as $kept) {
                    $log("  kept your version: {$kept}");
                }
                foreach ($report['notes'] ?? [] as $note) {
                    $log("  note: {$note}");
                }
            } catch (\Throwable $e) {
                $result['errors'][] = "{$handle}: {$e->getMessage()}";
                $log("FAILED {$handle}: {$e->getMessage()}");
            }
        }

        // Also invalidates {% cache %} blocks, which are tagged by element.
        Craft::$app->getElements()->invalidateAllCaches();

        return $result;
    }

    public static function emptyReport(): array
    {
        return ['updated' => [], 'added' => [], 'kept' => [], 'trashed' => [], 'unchanged' => 0, 'notes' => []];
    }

    public static function mergeReports(array ...$reports): array
    {
        $merged = self::emptyReport();
        foreach ($reports as $report) {
            foreach (['updated', 'added', 'kept', 'trashed', 'notes'] as $key) {
                $merged[$key] = array_merge($merged[$key], $report[$key] ?? []);
            }
            $merged['unchanged'] += $report['unchanged'] ?? 0;
        }

        return $merged;
    }

    private function updatePackage(string $handle, array $update, callable $log): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $dir = (string)$packageManager->getPackagePath($handle);

        // The installed version is the baseline: keep it aside, then let the
        // signed download replace the Library copy.
        $baseline = self::baselineDir($handle, $update['from']);
        if (is_dir($baseline)) {
            FileHelper::removeDirectory($baseline);
        }
        FileHelper::copyDirectory($dir, $baseline);

        $path = (new Commerce24MarketplaceRepository())->fetchPackage($handle);
        $importer = new PackageImportService();
        $validation = $importer->validatePackage($path, true);
        if (!$validation->valid) {
            throw new \Exception('The new version failed validation: ' . implode(' ', $validation->errors));
        }
        $summary = $importer->importPackage($validation, ['install' => false, 'overwriteConflicts' => true]);
        @unlink($path);
        if ($summary['errors']) {
            throw new \Exception(implode(' ', $summary['errors']));
        }
        $log("Downloaded {$handle} {$update['to']} (signature {$validation->signature?->keyId})");

        $record = $packageManager->getPackageByHandle($handle);
        if (!in_array($record?->status, ['installed', 'enabled'], true)) {
            return self::emptyReport();
        }

        try {
            return match ($update['type']) {
                'section' => $this->applySection($record, $baseline, $dir),
                'template' => $this->applyTemplate($baseline, $dir),
                'theme' => (new ThemeUpdater())->updateTheme($record, $baseline, $dir, $log),
                'starter-kit' => $this->applyKit($record, $baseline, $dir, $log),
            };
        } catch (\Throwable $e) {
            // Back to the installed version, so running the update again
            // compares against what this site really had; whatever was
            // already applied then counts as done (live == incoming).
            PackageArchiveHelper::replaceDirectory($baseline, $dir);
            $packageManager->discoverPackages();
            throw $e;
        }
    }

    public static function baselineDir(string $handle, string $version): string
    {
        return Craft::getAlias('@storage') . "/site7-studio/library-baselines/{$handle}/{$version}";
    }

    // ------------------------------------------------------------- sections

    private function applySection(PackageRecord $record, string $baseline, string $dir): array
    {
        $old = json_decode((string)file_get_contents("{$baseline}/" . SectionSchemaService::FILE), true) ?: [];
        $new = json_decode((string)file_get_contents("{$dir}/" . SectionSchemaService::FILE), true) ?: [];
        $oldItems = [];
        foreach ($old['items'] ?? [] as $item) {
            $oldItems["{$item['kind']}.{$item['uid']}"] = $item['config'];
        }
        $newItems = [];
        foreach ($new['items'] ?? [] as $item) {
            $newItems[] = ['path' => "{$item['kind']}.{$item['uid']}", 'label' => "{$item['kind']} {$item['handle']}", 'config' => $item['config']];
        }
        $report = $this->applyItems($oldItems, $newItems, $record);

        // The block template.
        $entryTypeHandle = null;
        foreach ($new['items'] ?? [] as $item) {
            if ($item['kind'] === 'entryTypes' && $item['uid'] === ($new['entryType'] ?? null)) {
                $entryTypeHandle = $item['handle'];
            }
        }
        if ($entryTypeHandle && is_file("{$dir}/template.twig")) {
            $target = "_blocks/{$entryTypeHandle}.twig";
            $live = Craft::getAlias('@templates') . "/{$target}";
            $decision = self::decideFile("{$baseline}/template.twig", $live, "{$dir}/template.twig");
            if ($decision === 'apply') {
                FileHelper::writeToFile($live, (string)file_get_contents("{$dir}/template.twig"));
                Site7Studio::getInstance()->installedFileBaseline->record($record->id, $entryTypeHandle, $target, $record->version, (string)PackageArchiveHelper::computeFileChecksum($live));
                $report['updated'][] = "template {$target}";
            } elseif ($decision === 'kept') {
                $report['kept'][] = "template {$target}";
            } else {
                $report['unchanged']++;
            }
        }

        return $report;
    }

    /**
     * Project config items through the rule, applied in the given
     * (dependency) order and saved right away.
     *
     * @param array<string, mixed> $oldItems path => config as installed
     * @param array[] $newItems [{path, label, config}] in dependency order
     * @param string[] $skipPaths items that are never compared (e.g. the page-builder field, whose blocks every Section package links in)
     */
    public function applyItems(array $oldItems, array $newItems, PackageRecord $record, array $skipPaths = []): array
    {
        $report = self::emptyReport();
        $projectConfig = Craft::$app->getProjectConfig();
        $apply = [];
        foreach ($newItems as $item) {
            $path = $item['path'];
            if (in_array($path, $skipPaths, true)) {
                continue;
            }
            $baselineHash = array_key_exists($path, $oldItems) ? self::configHash($oldItems[$path]) : null;
            $liveHash = self::configHash($projectConfig->get($path));
            // Fields are shared: the Theme or another block may have
            // installed this one, with its own version of the config. Still
            // as some installed Library package shipped it = untouched here.
            if ($baselineHash !== null && $liveHash !== $baselineHash
                && in_array($liveHash, $this->libraryConfigHashes($path, $record->handle), true)) {
                $liveHash = $baselineHash;
            }
            $decision = self::decide($baselineHash, $liveHash, self::configHash($item['config']));
            if ($decision === 'apply') {
                $apply[$path] = $item['config'];
                $report[$liveHash === null ? 'added' : 'updated'][] = $item['label'];
            } elseif ($decision === 'kept') {
                $report['kept'][] = $item['label'];
            } else {
                $report['unchanged']++;
            }
        }
        foreach ([false, true] as $force) {
            foreach ($apply as $path => $config) {
                $projectConfig->set($path, $config, "Site7 Studio: update {$record->handle} to {$record->version}", true, $force);
            }
        }
        // Written now, not at the end of the request: the package's version
        // is already recorded, so a later failure must not lose these.
        if ($apply) {
            $projectConfig->saveModifiedConfigData();
        }

        return $report;
    }

    // ---------------------------------------------------------------- content

    private function applyTemplate(string $baseline, string $dir): array
    {
        $meta = json_decode((string)file_get_contents("{$dir}/" . TemplateBuilder::META_FILE), true) ?: [];
        $pageId = (int)(new \craft\db\Query())->select(['id'])->from('{{%elements}}')->where(['uid' => $meta['entryUid'] ?? ''])->scalar();

        return $this->applyContent($baseline, $dir, $pageId ? ['entryIds' => [$pageId]] : null);
    }

    /**
     * The kit: Templates it now requires that aren't here yet (downloaded
     * and installed with their blocks), then its own content - menus,
     * sitemap rows, demo entries - through the rule.
     */
    private function applyKit(PackageRecord $record, string $baseline, string $dir, callable $log): array
    {
        $report = self::emptyReport();
        $packageManager = Site7Studio::getInstance()->packageManager;
        $manifest = json_decode((string)file_get_contents("{$dir}/manifest.json"), true) ?: [];
        $catalog = (new LibraryDistribution())->catalog();
        $newTemplates = array_values(array_filter(
            $manifest['requires']['templates'] ?? [],
            fn($handle) => !in_array($packageManager->getPackageByHandle($handle)?->status, ['installed', 'enabled'], true)
        ));
        if ($newTemplates) {
            $download = [];
            foreach ($newTemplates as $handle) {
                $download = array_merge($download, LibraryDistribution::closure($handle, $catalog)['handles']);
            }
            if ($errors = (new LibraryDistribution())->download(array_unique($download), $log)) {
                throw new \Exception(implode(' ', $errors));
            }
            foreach ($newTemplates as $handle) {
                if (!$packageManager->installPackage($handle) || !$packageManager->enablePackage($handle)) {
                    throw new \Exception("The new page '{$handle}' could not be installed: " . implode(' ', $packageManager->getLastInstallWarnings()));
                }
                $report['added'][] = "page {$handle}";
            }
        }

        $meta = json_decode((string)file_get_contents("{$dir}/" . KitBuilder::META_FILE), true) ?: [];
        $sectionUids = [];
        foreach ($meta['demoSections'] ?? [] as $sectionHandle) {
            if ($section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle)) {
                $sectionUids[] = $section->uid;
            }
        }

        return self::mergeReports($report, $this->applyContent($baseline, $dir, ['sectionUids' => $sectionUids], KitBuilder::PLUGIN_TABLES));
    }

    /**
     * Content elements and plugin tables through the rule. $live says what
     * to export as this site's current state: ['entryIds' => [...]] or
     * ['sectionUids' => [...]] (null: nothing here yet).
     *
     * @param string[] $pluginTables whole tables compared and replaced as one item
     */
    public function applyContent(string $baseline, string $dir, ?array $live, array $pluginTables = []): array
    {
        $report = self::emptyReport();

        // This site's current state, in the same format.
        $liveDir = Craft::$app->getRuntimePath() . '/site7-library-live-' . \craft\helpers\StringHelper::randomString(6);
        $liveSignatures = [];
        $liveTables = [];
        if ($live !== null) {
            (new SiteKitContent())->exportToDir($liveDir, $live['sectionUids'] ?? null, $pluginTables ?: false, $live['entryIds'] ?? null);
            $liveSignatures = SiteKitContent::signatures("{$liveDir}/content");
            $liveTables = SiteKitContent::pluginTableSignatures("{$liveDir}/content", $pluginTables);
            FileHelper::removeDirectory($liveDir);
        }
        $base = SiteKitContent::signatures("{$baseline}/content");
        $incoming = SiteKitContent::signatures("{$dir}/content");
        // Named as installed: "kept your version" refers to what this site had.
        $titles = self::titles("{$baseline}/content") + self::titles("{$dir}/content");

        $insert = [];
        $replace = [];
        $trash = [];
        foreach (array_unique(array_merge(array_keys($base), array_keys($incoming))) as $id) {
            $label = $titles[$id] ?? "element #{$id}";
            $b = $base[$id] ?? null;
            $l = $liveSignatures[$id] ?? (self::elementExists($id) ? 'outside-scope' : null);
            $i = $incoming[$id] ?? null;

            if ($i === null) {
                if ($b !== null && $l === $b) {
                    $trash[] = $id;
                    $report['trashed'][] = $label;
                } elseif ($l !== null && $l !== $b) {
                    $report['kept'][] = "{$label} (removed upstream, edited here)";
                }
                continue;
            }
            $decision = self::decide($b, $l, $i);
            if ($decision === 'apply') {
                if ($l === null) {
                    $insert[] = $id;
                    $report['added'][] = $label;
                } else {
                    $replace[] = $id;
                    $report['updated'][] = $label;
                }
            } elseif ($decision === 'kept') {
                $report['kept'][] = $label;
            } else {
                $report['unchanged']++;
            }
        }

        $baseTables = SiteKitContent::pluginTableSignatures("{$baseline}/content", $pluginTables);
        $incomingTables = SiteKitContent::pluginTableSignatures("{$dir}/content", $pluginTables);
        $replaceTables = [];
        foreach ($incomingTables as $table => $hash) {
            $decision = self::decide($baseTables[$table] ?? null, $liveTables[$table] ?? null, $hash);
            if ($decision === 'apply') {
                $replaceTables[] = $table;
                $report['updated'][] = "table {$table}";
            } elseif ($decision === 'kept') {
                $report['kept'][] = "table {$table}";
            } else {
                $report['unchanged']++;
            }
        }

        if ($insert || $replace || $replaceTables) {
            (new SiteKitContent())->import($dir, array_merge($insert, $replace), $replace, $replaceTables);
        }
        if ($trash) {
            Craft::$app->getDb()->createCommand()->update('{{%elements}}', ['dateDeleted' => (new \DateTime())->format('Y-m-d H:i:s')], ['id' => $trash])->execute();
        }

        return $report;
    }

    // --------------------------------------------------------------- helpers

    /**
     * The three-way decision for one item, through PackageUpdatePlanner::classify().
     *
     * @return string apply | kept | none
     */
    public static function decide(?string $baseline, ?string $live, ?string $incoming): string
    {
        if ($baseline === null) {
            // New upstream: add it unless something different is already here.
            return $live === null ? 'apply' : ($live === $incoming ? 'none' : 'kept');
        }
        $result = (new PackageUpdatePlanner())->classify(
            ['checksum' => $baseline, 'targetPath' => '', 'resourceHandle' => ''],
            $live,
            $incoming
        )['result'];

        return match ($result) {
            PackageUpdatePlanner::RESULT_SAFE_UPDATE => 'apply',
            PackageUpdatePlanner::RESULT_CONFLICT => $live === $incoming ? 'none' : 'kept',
            PackageUpdatePlanner::RESULT_LOCAL_MODIFICATION, PackageUpdatePlanner::RESULT_REMOVAL_CONFLICT => 'kept',
            default => 'none',
        };
    }

    /** decide() for three file paths (missing files count as absent). */
    public static function decideFile(string $baseline, string $live, string $incoming): string
    {
        return self::decide(
            PackageArchiveHelper::computeFileChecksum($baseline),
            PackageArchiveHelper::computeFileChecksum($live),
            PackageArchiveHelper::computeFileChecksum($incoming)
        );
    }

    /** @var array<string, string[]>|null project config path => config hashes the installed Library packages ship */
    private ?array $libraryConfigIndex = null;

    /**
     * Hashes of the configs the other installed Library packages (their
     * Library copies = installed versions) ship for $path: the Theme's
     * schema and every Section package's.
     *
     * @return string[]
     */
    private function libraryConfigHashes(string $path, string $exceptHandle): array
    {
        if ($this->libraryConfigIndex === null) {
            $this->libraryConfigIndex = [];
            foreach (glob(Craft::getAlias('@packages') . '/*/schema.json') ?: [] as $file) {
                $handle = basename(dirname($file));
                $schema = json_decode((string)file_get_contents($file), true) ?: [];
                if (isset($schema['sourceSites'])) {
                    $schema = (new \site7\studio\services\theme\ThemeSchemaService())->forThisSite($schema);
                }
                foreach ($schema['items'] ?? [] as $item) {
                    $itemPath = $item['path'] ?? (isset($item['kind'], $item['uid']) ? "{$item['kind']}.{$item['uid']}" : null);
                    if ($itemPath !== null) {
                        $this->libraryConfigIndex[$itemPath][$handle] = self::configHash($item['config'] ?? null);
                    }
                }
            }
        }

        return array_values(array_diff_key($this->libraryConfigIndex[$path] ?? [], [$exceptHandle => true]));
    }

    private static function configHash(mixed $config): ?string
    {
        if ($config === null) {
            return null;
        }
        if (is_array($config)) {
            $config = ProjectConfigHelper::cleanupConfig($config);
            self::ksortRecursive($config);
        }

        return md5(json_encode($config));
    }

    private static function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }

    private static function elementExists(int $id): bool
    {
        return (new \craft\db\Query())->from('{{%elements}}')->where(['id' => $id, 'dateDeleted' => null])->exists();
    }

    /** @return array<int, string> element ID => "title" or its type */
    private static function titles(string $contentDir): array
    {
        $titles = [];
        foreach (json_decode((string)@file_get_contents("{$contentDir}/tables/elements_sites.json"), true) ?: [] as $row) {
            $titles[(int)$row['elementId']] = $row['title'] ? "\"{$row['title']}\"" : "element #{$row['elementId']}";
        }

        return $titles;
    }

    public static function summary(array $report): string
    {
        return sprintf('%d updated, %d added, %d kept (edited here), %d removed, %d unchanged',
            count($report['updated']), count($report['added']), count($report['kept']), count($report['trashed']), $report['unchanged']);
    }
}
