<?php

namespace site7\studio\services\library;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use site7\studio\repositories\marketplace\Commerce24MarketplaceRepository;
use site7\studio\services\import\SectionSchemaService;
use site7\studio\services\PackageImportService;
use site7\studio\services\sitekit\SiteKitContent;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\services\synchronization\PackageUpdatePlanner;
use site7\studio\services\template\TemplateBuilder;
use site7\studio\Site7Studio;

/**
 * Updates installed Library packages from Commerce24 (docs/53_LIBRARY_UPDATES.md).
 *
 * The rule, for every file, structure item and content element: the
 * version this site installed is the baseline - the package's own copy in
 * the Library, kept aside before the new version replaces it - and
 * PackageUpdatePlanner::classify() decides:
 *   unchanged here, changed upstream  -> updated
 *   changed here (the customer's edit) -> kept, reported
 *   deleted here                       -> stays deleted
 *   gone upstream                      -> content: moved to the trash if
 *                                         unchanged here; structure: kept
 *
 * Supported: Section packages (format v2: block template + fields/entry
 * types) and Template packages (format v2: page content). Theme and Starter
 * Kit updates come later (docs/53 §8).
 */
class LibraryUpdater extends Component
{
    public const SUPPORTED_TYPES = ['section', 'template'];

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
                'supported' => in_array($record->type, self::SUPPORTED_TYPES, true),
            ];
        }

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
        $backup = Craft::$app->getDb()->backup();
        $log('Backup: ' . $backup);

        foreach ($updates as $handle => $update) {
            if (!$update['supported']) {
                $result['skipped'][] = $handle;
                $log("{$handle}: updates of {$update['type']} packages aren't supported yet - skipped");
                continue;
            }
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
            } catch (\Throwable $e) {
                $result['errors'][] = "{$handle}: {$e->getMessage()}";
                $log("FAILED {$handle}: {$e->getMessage()}");
            }
        }

        // Also invalidates {% cache %} blocks, which are tagged by element.
        Craft::$app->getElements()->invalidateAllCaches();

        return $result;
    }

    /**
     * @return array{updated: string[], added: string[], kept: string[], trashed: string[], unchanged: int}
     */
    private function updatePackage(string $handle, array $update, callable $log): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $dir = (string)$packageManager->getPackagePath($handle);

        // The installed version is the baseline: keep it aside, then let the
        // signed download replace the Library copy.
        $baseline = Craft::getAlias('@storage') . "/site7-studio/library-baselines/{$handle}/{$update['from']}";
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
            return ['updated' => [], 'added' => [], 'kept' => [], 'trashed' => [], 'unchanged' => 0];
        }

        try {
            return match ($update['type']) {
                'section' => $this->applySection($record, $baseline, $dir),
                'template' => $this->applyTemplate($baseline, $dir),
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

    // ------------------------------------------------------------- sections

    private function applySection($record, string $baseline, string $dir): array
    {
        $report = ['updated' => [], 'added' => [], 'kept' => [], 'trashed' => [], 'unchanged' => 0];
        $old = json_decode((string)file_get_contents("{$baseline}/" . SectionSchemaService::FILE), true) ?: [];
        $new = json_decode((string)file_get_contents("{$dir}/" . SectionSchemaService::FILE), true) ?: [];
        $oldItems = [];
        foreach ($old['items'] ?? [] as $item) {
            $oldItems["{$item['kind']}.{$item['uid']}"] = $item['config'];
        }

        // Fields and entry types, in the schema's dependency order.
        $projectConfig = Craft::$app->getProjectConfig();
        $apply = [];
        foreach ($new['items'] ?? [] as $item) {
            $path = "{$item['kind']}.{$item['uid']}";
            $label = "{$item['kind']} {$item['handle']}";
            $baselineHash = isset($oldItems[$path]) ? self::configHash($oldItems[$path]) : null;
            $liveHash = self::configHash($projectConfig->get($path));
            // Fields are shared: the Theme or another block may have
            // installed this one, with its own version of the config. Still
            // as some installed Library package shipped it = untouched here.
            if ($baselineHash !== null && $liveHash !== $baselineHash
                && in_array($liveHash, $this->libraryConfigHashes($path, $record->handle), true)) {
                $liveHash = $baselineHash;
            }
            $decision = self::decide($baselineHash, $liveHash, self::configHash($item['config']));
            match ($decision) {
                'apply' => $apply[$path] = $item['config'],
                'kept' => $report['kept'][] = $label,
                default => $report['unchanged']++,
            };
            if ($decision === 'apply') {
                $report[$projectConfig->get($path) === null ? 'added' : 'updated'][] = $label;
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
            $decision = self::decide(
                PackageArchiveHelper::computeFileChecksum("{$baseline}/template.twig"),
                PackageArchiveHelper::computeFileChecksum($live),
                PackageArchiveHelper::computeFileChecksum("{$dir}/template.twig")
            );
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

    // ------------------------------------------------------------- templates

    private function applyTemplate(string $baseline, string $dir): array
    {
        $report = ['updated' => [], 'added' => [], 'kept' => [], 'trashed' => [], 'unchanged' => 0];
        $meta = json_decode((string)file_get_contents("{$dir}/" . TemplateBuilder::META_FILE), true) ?: [];
        $pageId = (int)(new \craft\db\Query())->select(['id'])->from('{{%elements}}')->where(['uid' => $meta['entryUid'] ?? ''])->scalar();

        // This site's current state of the page, in the same format.
        $liveDir = Craft::$app->getRuntimePath() . '/site7-library-live-' . \craft\helpers\StringHelper::randomString(6);
        $live = [];
        if ($pageId) {
            (new SiteKitContent())->exportToDir($liveDir, null, false, [$pageId]);
            $live = SiteKitContent::signatures("{$liveDir}/content");
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
            $l = $live[$id] ?? (self::elementExists($id) ? 'outside-page' : null);
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
            if ($b === null) {
                if ($l === null) {
                    $insert[] = $id;
                    $report['added'][] = $label;
                } elseif ($l !== $i) {
                    $report['kept'][] = $label;
                } else {
                    $report['unchanged']++;
                }
                continue;
            }
            match (self::decide($b, $l, $i)) {
                'apply' => [$replace[] = $id, $report['updated'][] = $label],
                'kept' => $report['kept'][] = $label,
                default => $report['unchanged']++,
            };
        }

        if ($insert || $replace) {
            (new SiteKitContent())->import($dir, array_merge($insert, $replace), $replace);
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
        if (!is_array($config)) {
            return null;
        }
        $config = ProjectConfigHelper::cleanupConfig($config);
        self::ksortRecursive($config);

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
