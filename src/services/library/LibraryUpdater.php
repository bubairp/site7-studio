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
    public const TYPE_ORDER = ['theme' => 0, 'design' => 1, 'section' => 2, 'template' => 3, 'starter-kit' => 4];

    public const AUTHOR_SITE_NOTE = 'This is the author site: its blocks and pages come from its own templates and reach servers by deploy, so Library updates don\'t apply here.';

    /**
     * The authorSite setting (config/site7-studio.php). $settings: for tests.
     */
    public static function isAuthorSite(?\site7\studio\models\Settings $settings = null): bool
    {
        $settings ??= Site7Studio::getInstance()?->getSettings();

        return (bool)($settings->authorSite ?? false);
    }

    /**
     * Installed Library packages that Commerce24 has a newer version of.
     *
     * @return array[] handle, type, name, from, to, releaseNotes, size, entitled, supported
     */
    public function availableUpdates(bool $fresh = false): array
    {
        if (self::isAuthorSite()) {
            return [];
        }
        $packageManager = Site7Studio::getInstance()->packageManager;
        $catalog = (new LibraryDistribution())->catalog($fresh);
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
            // A page's new version can need blocks this site doesn't have yet (docs/43 #53).
            $blocks = $record->type === 'template' ? $this->missingBlocks((array)($entry['requires']['sections'] ?? []), $catalog) : ['install' => [], 'needsPlan' => []];
            $updates[array_key_last($updates)]['alsoInstalls'] = array_map(fn($handle) => $catalog[$handle]['name'] ?? $handle, $blocks['install']);
            $updates[array_key_last($updates)]['needsPlan'] = array_map(fn($handle) => $catalog[$handle]['name'] ?? $handle, $blocks['needsPlan']);
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
        if (self::isAuthorSite()) {
            $result['errors'][] = self::AUTHOR_SITE_NOTE;
            return $result;
        }
        $updates = [];
        foreach ($this->availableUpdates(true) as $update) {
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
        $dbBackup = Craft::$app->getDb()->backup();
        $log('Backup: ' . $dbBackup);

        foreach ($updates as $handle => $update) {
            // Blocks, pages and kits are built on the Theme: after a failed
            // Theme update they're not attempted (docs/43 #52).
            if ($result['themeFailed'] ?? false) {
                $result['skipped'][] = $handle;
                $log("NOT ATTEMPTED {$handle}: the Theme update failed");
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
                foreach (self::reportLines($report) as $line) {
                    $log($line);
                }
            } catch (UpdateSkippedException $e) {
                // Not applied, on purpose: the installed version stays (updatePackage() put its Library copy back).
                $result['skipped'][] = $handle;
                $log("SKIPPED {$handle}: {$e->getMessage()}");
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                if ($update['type'] === 'theme') {
                    $result['themeFailed'] = true;
                    // The database is not restored automatically: that would also
                    // undo content edited while the update ran (docs/53 §4).
                    $message .= " The database was backed up before this update: {$dbBackup}. If the site doesn't work, restore it with: php craft db/restore {$dbBackup}";
                }
                $result['errors'][] = "{$handle}: {$message}";
                $log("FAILED {$handle}: {$message}");
            }
        }
        unset($result['themeFailed']);

        // Also invalidates {% cache %} blocks, which are tagged by element.
        Craft::$app->getElements()->invalidateAllCaches();

        return $result;
    }

    /**
     * Moves one installed package to any published version, newer or older
     * (docs/60: choosing a design version). Same rule as an update: files
     * edited here are kept and reported.
     *
     * @return array{updated: string[], skipped: string[], errors: string[], report: array<string, array>}
     */
    public function changeVersion(string $handle, string $version, ?callable $log = null): array
    {
        $log ??= fn(string $line) => null;
        $result = ['updated' => [], 'skipped' => [], 'errors' => [], 'report' => []];
        $record = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        if (!$record || !in_array($record->status, ['installed', 'enabled'], true)) {
            $result['errors'][] = "{$handle}: not installed on this site";
            return $result;
        }
        if (!isset(self::TYPE_ORDER[$record->type])) {
            $result['errors'][] = "{$handle}: its type can't change version";
            return $result;
        }
        if ((string)$record->version === $version) {
            $result['skipped'][] = $handle;
            $log("{$handle} is already {$version}");
            return $result;
        }
        $published = array_column((new LibraryDistribution())->versions($handle), 'version');
        if (!in_array($version, $published, true)) {
            $result['errors'][] = "{$handle}: version {$version} isn't published" . ($published ? ' (published: ' . implode(', ', $published) . ')' : '');
            return $result;
        }
        $update = ['handle' => $handle, 'type' => $record->type, 'name' => $record->name, 'from' => (string)$record->version, 'to' => $version];

        $log('Backing up the database…');
        $log('Backup: ' . Craft::$app->getDb()->backup());
        try {
            $report = $this->updatePackage($handle, $update, $log);
            $result['updated'][] = $handle;
            $result['report'][$handle] = $report;
            $log(sprintf('Changed %s %s -> %s: %s', $handle, $update['from'], $version, self::summary($report)));
            foreach (self::reportLines($report) as $line) {
                $log($line);
            }
        } catch (\Throwable $e) {
            $result['errors'][] = "{$handle}: {$e->getMessage()}";
            $log("FAILED {$handle}: {$e->getMessage()}");
        }
        Craft::$app->getElements()->invalidateAllCaches();

        return $result;
    }

    public static function emptyReport(): array
    {
        // removed: files the new version dropped (deleted here because unchanged);
        // deletedHere: package files this site deleted, not recreated
        return ['updated' => [], 'added' => [], 'kept' => [], 'trashed' => [], 'removed' => [], 'deletedHere' => [], 'unchanged' => 0, 'notes' => []];
    }

    public static function mergeReports(array ...$reports): array
    {
        $merged = self::emptyReport();
        foreach ($reports as $report) {
            foreach (['updated', 'added', 'kept', 'trashed', 'removed', 'deletedHere', 'notes'] as $key) {
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
        // Other packages' Library copies change as this run updates them.
        $this->libraryConfigIndex = null;

        try {
            // The version this update is for: the latest, or the one changeVersion() asked for.
            $path = (new Commerce24MarketplaceRepository())->fetchPackage($handle, $update['to'] ?? null);
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

            return match ($update['type']) {
                'section' => $this->applySection($record, $baseline, $dir),
                'template' => $this->applyTemplate($baseline, $dir, $log),
                'theme' => (new ThemeUpdater())->updateTheme($record, $baseline, $dir, $log),
                'starter-kit' => $this->applyKit($record, $baseline, $dir, $log),
                'design' => (new \site7\studio\services\design\DesignUpdater())->apply($record, $baseline, $dir, $log),
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

        // The block template and the manifest's owned files, file by file
        // (PackageFileUpdater: kept when edited here, never recreated when
        // deleted here, removed upstream only when unchanged).
        $root = dirname(rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/'));
        $files = (new PackageFileUpdater($root, new InstalledFileBaselineStore()))
            ->apply($record->id, $record->handle, (string)$record->version, $baseline, $dir);
        foreach (['updated', 'added', 'kept', 'removed', 'deletedHere', 'notes'] as $key) {
            $report[$key] = array_merge($report[$key] ?? [], $files[$key]);
        }
        $report['unchanged'] += $files['unchanged'];
        foreach ($files['templates'] as $template) {
            // The new version's Tailwind classes (docs/59); never runs npm.
            if (($styles = \site7\studio\services\theme\TailwindSafelist::afterBlockInstalled($record->name, $template)) !== null) {
                $report['notes'][] = $styles;
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

    /**
     * A page: first the blocks its new version needs that aren't installed
     * here (downloaded and installed, like applyKit() does for pages), then
     * its content through the rule. A block the site's plan doesn't include
     * skips the whole page update, so the page stays complete (docs/43 #53).
     */
    private function applyTemplate(string $baseline, string $dir, callable $log): array
    {
        $report = self::emptyReport();
        $manifest = json_decode((string)file_get_contents("{$dir}/manifest.json"), true) ?: [];
        $needed = (array)($manifest['requires']['sections'] ?? []);
        if ($this->missingBlocks($needed, null)['install']) {
            $catalog = (new LibraryDistribution())->catalog();
            $blocks = $this->missingBlocks($needed, $catalog);
            if ($blocks['needsPlan']) {
                $names = implode(', ', array_map(fn($handle) => $catalog[$handle]['name'] ?? $handle, $blocks['needsPlan']));
                throw new UpdateSkippedException("the new version uses {$names}, which this site's plan doesn't include. The page keeps its current version; it updates once the plan includes " . (count($blocks['needsPlan']) > 1 ? 'them' : 'it') . '.');
            }
            $download = [];
            foreach ($blocks['install'] as $handle) {
                $download = array_merge($download, LibraryDistribution::closure($handle, $catalog)['handles']);
            }
            if ($errors = (new LibraryDistribution())->download(array_unique($download), $log)) {
                throw new \Exception(implode(' ', $errors));
            }
            $packageManager = Site7Studio::getInstance()->packageManager;
            foreach ($blocks['install'] as $handle) {
                if (!$packageManager->installPackage($handle) || !$packageManager->enablePackage($handle)) {
                    throw new \Exception("The block '{$handle}' the new version needs could not be installed: " . implode(' ', $packageManager->getLastInstallWarnings()));
                }
                $report['added'][] = "block {$handle}";
                $log("Installed block {$handle}, which the new version uses");
            }
        }

        $meta = json_decode((string)file_get_contents("{$dir}/" . TemplateBuilder::META_FILE), true) ?: [];
        $pageId = (int)(new \craft\db\Query())->select(['id'])->from('{{%elements}}')->where(['uid' => $meta['entryUid'] ?? ''])->scalar();

        return self::mergeReports($report, $this->applyContent($baseline, $dir, $pageId ? ['entryIds' => [$pageId]] : null));
    }

    /**
     * Of these block (Section) packages, the ones not installed here, split
     * into those this site can install and those its plan doesn't include
     * (Commerce24's `entitled`). Without a catalog every missing block counts
     * as installable - a cheap "anything missing?" check.
     *
     * @param string[] $sectionHandles
     * @return array{install: string[], needsPlan: string[]}
     */
    private function missingBlocks(array $sectionHandles, ?array $catalog): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $result = ['install' => [], 'needsPlan' => []];
        foreach (array_unique($sectionHandles) as $handle) {
            if (in_array($packageManager->getPackageByHandle($handle)?->status, ['installed', 'enabled'], true)) {
                continue;
            }
            $entitled = $catalog === null || (isset($catalog[$handle]) && ($catalog[$handle]['entitled'] ?? true));
            $result[$entitled ? 'install' : 'needsPlan'][] = $handle;
        }

        return $result;
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
        // Sections the new version needs that aren't here yet (docs/49 §2c).
        if ($added = (new \site7\studio\services\theme\ThemeInstaller())->addSections(\site7\studio\services\starterkit\KitInstaller::kitSections($dir))) {
            $report['added'][] = 'sections ' . implode(', ', $added);
        }
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
            $installedPages = $this->installedTemplatePages();
            foreach ($newTemplates as $handle) {
                // A page renamed on the dev site comes back under a new
                // handle (handles follow the slug) but is the same entry.
                // Installing it as new kept this site's page and added the
                // new blocks into it (docs/43 #64): update it from the
                // package this site installed it with instead.
                $newDir = (string)$packageManager->getPackagePath($handle);
                $entryUid = (json_decode((string)@file_get_contents("{$newDir}/" . TemplateBuilder::META_FILE), true) ?: [])['entryUid'] ?? '';
                $oldHandle = self::pageOwner($installedPages, (string)$entryUid, $handle);
                if ($oldHandle !== null) {
                    $oldDir = (string)$packageManager->getPackagePath($oldHandle);
                    $report = self::mergeReports($report, $this->applyTemplate($oldDir, $newDir, $log));
                }
                // A renamed page's content went through the rule above; importing
                // it again would add back the blocks of a page kept whole.
                if (!$packageManager->installPackage($handle, $oldHandle === null) || !$packageManager->enablePackage($handle)) {
                    throw new \Exception("The new page '{$handle}' could not be installed: " . implode(' ', $packageManager->getLastInstallWarnings()));
                }
                if ($oldHandle !== null) {
                    $old = $packageManager->getPackageByHandle($oldHandle);
                    $old->status = 'available';
                    $old->save();
                    unset($installedPages[$oldHandle]);
                    $installedPages[$handle] = $entryUid;
                    $report['updated'][] = "page {$oldHandle} (now {$handle})";
                    $log("Page {$oldHandle} is now {$handle}: updated in place");
                } else {
                    $report['added'][] = "page {$handle}";
                }
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
     * What SiteKitContent::signatures() needs to read a content directory as
     * this site would hold it now: the field layout element UIDs this site
     * has, and the elements it references that this site can't hold there
     * any more - trashed or missing here, or of a type the field no longer
     * relates to (blog categories became entries, docs/43 #65).
     */
    private static function asOnThisSite(string $contentDir): array
    {
        $fieldKeys = [];
        $relatesTo = []; // layout element UID or field UID => element type a relation field holds
        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            foreach ($layout->getCustomFieldElements() as $element) {
                $fieldKeys[$element->uid] = true;
                $field = $element->getField();
                if ($field instanceof \craft\fields\BaseRelationField) {
                    $relatesTo[$element->uid] = $field::elementType();
                    $relatesTo[$field->uid] = $field::elementType();
                }
            }
        }

        $referenced = []; // id => element types the referencing fields hold (null: unknown)
        foreach (json_decode((string)@file_get_contents("{$contentDir}/tables/relations.json"), true) ?: [] as $row) {
            $fieldUid = is_string($row['fieldId'] ?? null) && str_starts_with($row['fieldId'], '@uid:') ? substr($row['fieldId'], 5) : null;
            $referenced[(int)$row['targetId']][] = $fieldUid !== null ? ($relatesTo[$fieldUid] ?? null) : null;
        }
        foreach (json_decode((string)@file_get_contents("{$contentDir}/tables/elements_sites.json"), true) ?: [] as $row) {
            $decoded = is_string($row['content'] ?? null) ? (json_decode($row['content'], true) ?: []) : [];
            foreach ($decoded as $key => $value) {
                if (is_array($value) && array_is_list($value)) {
                    foreach ($value as $item) {
                        if (is_int($item)) {
                            $referenced[$item][] = $relatesTo[$key] ?? null;
                        }
                    }
                }
            }
        }
        $missing = [];
        if ($referenced) {
            $types = (new \craft\db\Query())->select(['id', 'type'])->from('{{%elements}}')
                ->where(['id' => array_keys($referenced), 'dateDeleted' => null])->pairs();
            foreach ($referenced as $id => $fieldTypes) {
                $type = $types[$id] ?? null;
                $fits = fn($fieldType) => $fieldType === null || is_a((string)$type, $fieldType, true);
                if ($type === null || !array_filter($fieldTypes, $fits)) {
                    $missing[$id] = true;
                }
            }
        }

        return ['fieldKeys' => $fieldKeys, 'missingIds' => $missing];
    }

    /**
     * The page (entry UID) each Template package installed here builds.
     *
     * @return array<string, string> handle => entryUid
     */
    private function installedTemplatePages(): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $pages = [];
        foreach ($packageManager->getAllPackages() as $record) {
            if ($record->type !== 'template' || !in_array($record->status, ['installed', 'enabled'], true)) {
                continue;
            }
            $meta = json_decode((string)@file_get_contents($packageManager->getPackagePath($record->handle) . '/' . TemplateBuilder::META_FILE), true) ?: [];
            if (!empty($meta['entryUid'])) {
                $pages[$record->handle] = $meta['entryUid'];
            }
        }

        return $pages;
    }

    /**
     * The installed Template package, other than $handle, that builds the
     * page $entryUid, or null.
     *
     * @param array<string, string> $installedPages handle => entryUid
     */
    public static function pageOwner(array $installedPages, string $entryUid, string $handle): ?string
    {
        if ($entryUid === '') {
            return null;
        }
        foreach ($installedPages as $installed => $uid) {
            if ($uid === $entryUid && $installed !== $handle) {
                return $installed;
            }
        }

        return null;
    }

    /**
     * Each nested entry's owner (a block's page, a nested block's block) in
     * a content directory.
     *
     * @return array<int, int> element ID => owner ID
     */
    private static function owners(string $contentDir): array
    {
        $owners = [];
        foreach (json_decode((string)@file_get_contents("{$contentDir}/tables/entries.json"), true) ?: [] as $row) {
            if (!empty($row['primaryOwnerId'])) {
                $owners[(int)$row['id']] = (int)$row['primaryOwnerId'];
            }
        }

        return $owners;
    }

    /**
     * The top-level element (the page) an element belongs to: itself when it
     * has no owner.
     *
     * @param array<int, int> $ownerOf element ID => owner ID
     */
    public static function pageOf(int $id, array $ownerOf): int
    {
        $seen = [];
        while (isset($ownerOf[$id]) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id = $ownerOf[$id];
        }

        return $id;
    }

    /**
     * Pages with blocks in which any element is kept as edited here. Their
     * other elements aren't updated either, so the page stays as the
     * customer has it. An element without blocks keeps the per-element rule.
     *
     * @param array<int, string> $actions element ID => insert | replace | trash | kept | kept-removed | none
     * @param array<int, int> $ownerOf element ID => owner ID
     * @return array<int, true> page ID => true
     */
    public static function pagesKeptWhole(array $actions, array $ownerOf): array
    {
        $hasBlocks = [];
        $edited = [];
        foreach ($actions as $id => $action) {
            $page = self::pageOf($id, $ownerOf);
            if ($page !== $id) {
                $hasBlocks[$page] = true;
            }
            if ($action === 'kept' || $action === 'kept-removed') {
                $edited[$page] = true;
            }
        }

        return array_intersect_key($edited, $hasBlocks);
    }

    /**
     * Content elements and plugin tables through the rule. $live says what
     * to export as this site's current state: ['entryIds' => [...]] or
     * ['sectionUids' => [...]] (null: nothing here yet).
     *
     * @param string[] $pluginTables whole tables compared and replaced as one item
     * @param int[] $skipIds elements left out: a Theme's content for sections this site doesn't have
     */
    public function applyContent(string $baseline, string $dir, ?array $live, array $pluginTables = [], array $skipIds = []): array
    {
        $skipSet = array_flip($skipIds);
        $report = self::emptyReport();

        // This site's current state, in the same format.
        $liveDir = Craft::$app->getRuntimePath() . '/site7-library-live-' . \craft\helpers\StringHelper::randomString(6);
        $liveSignatures = [];
        $liveTables = [];
        $ownerOf = [];
        if ($live !== null) {
            (new SiteKitContent())->exportToDir($liveDir, $live['sectionUids'] ?? null, $pluginTables ?: false, $live['entryIds'] ?? null, false);
            // Its relation rows can outlive a field's type change, too.
            $liveSignatures = SiteKitContent::signatures("{$liveDir}/content", self::asOnThisSite("{$liveDir}/content"));
            $liveTables = SiteKitContent::pluginTableSignatures("{$liveDir}/content", $pluginTables);
            $ownerOf = self::owners("{$liveDir}/content");
            FileHelper::removeDirectory($liveDir);
        }
        $ownerOf += self::owners("{$baseline}/content") + self::owners("{$dir}/content");
        // The installed version as this site would hold it now: fields and
        // elements an earlier update removed here aren't the customer's edits.
        $base = SiteKitContent::signatures("{$baseline}/content", self::asOnThisSite("{$baseline}/content"));
        $incoming = SiteKitContent::signatures("{$dir}/content");
        // Named as installed: "kept your version" refers to what this site had.
        $titles = self::titles("{$baseline}/content") + self::titles("{$dir}/content");

        $actions = []; // id => insert | replace | trash | kept | kept-removed | none
        foreach (array_unique(array_merge(array_keys($base), array_keys($incoming))) as $id) {
            if (isset($skipSet[$id])) {
                continue;
            }
            $b = $base[$id] ?? null;
            $l = $liveSignatures[$id] ?? (self::elementExists($id) ? 'outside-scope' : null);
            $i = $incoming[$id] ?? null;

            if ($i === null) {
                $actions[$id] = $b !== null && $l === $b ? 'trash' : ($l !== null && $l !== $b ? 'kept-removed' : 'none');
                continue;
            }
            if ($b === null && $l === 'outside-scope') {
                // New to this package but already on this site, outside it: an
                // image or entry another page uses. Left as it is; the
                // package's links to it still arrive (not the customer's edit).
                $actions[$id] = 'none';
                continue;
            }
            $decision = self::decide($b, $l, $i);
            $actions[$id] = $decision === 'apply' ? ($l === null ? 'insert' : 'replace') : $decision;
        }

        // A page is kept whole when any part of it was edited here: updating
        // only the parts the customer didn't touch could put their title over
        // a different article (docs/53 §2).
        $keptPages = self::pagesKeptWhole($actions, $ownerOf);
        $notApplied = array_fill_keys(array_keys($keptPages), 0);
        foreach ($actions as $id => $action) {
            $page = self::pageOf($id, $ownerOf);
            if (isset($keptPages[$page]) && $action !== 'none') {
                $notApplied[$page] += in_array($action, ['insert', 'replace', 'trash'], true) ? 1 : 0;
                $actions[$id] = 'page-kept';
            }
        }

        $insert = [];
        $replace = [];
        $trash = [];
        foreach ($actions as $id => $action) {
            $label = $titles[$id] ?? "element #{$id}";
            if ($action === 'insert') {
                $insert[] = $id;
                $report['added'][] = $label;
            } elseif ($action === 'replace') {
                $replace[] = $id;
                $report['updated'][] = $label;
            } elseif ($action === 'trash') {
                $trash[] = $id;
                $report['trashed'][] = $label;
            } elseif ($action === 'kept') {
                $report['kept'][] = $label;
            } elseif ($action === 'kept-removed') {
                $report['kept'][] = "{$label} (removed upstream, edited here)";
            } elseif ($action === 'none') {
                $report['unchanged']++;
            }
        }
        foreach ($notApplied as $page => $count) {
            $report['kept'][] = ($titles[$page] ?? "element #{$page}") . ' (whole page: edited here'
                . ($count ? ", {$count} upstream " . ($count === 1 ? 'change' : 'changes') . ' not applied' : '') . ')';
        }

        $baseTables = SiteKitContent::pluginTableSignatures("{$baseline}/content", $pluginTables);
        $incomingTables = SiteKitContent::pluginTableSignatures("{$dir}/content", $pluginTables);
        $replaceTables = [];
        foreach ($incomingTables as $table => $hash) {
            $decision = self::decide($baseTables[$table] ?? null, self::liveTableForDecision($baseTables[$table] ?? null, $liveTables[$table] ?? null), $hash);
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
     * A plugin table new in this version (no baseline) that is empty here counts as not
     * here: the plugin creates its table on install, so an empty one is nobody's edit
     * (seo_sitemap joined the kit in Site7 Studio def9071 and was kept as "edited here").
     */
    public static function liveTableForDecision(?string $baseline, ?string $live): ?string
    {
        return $baseline === null && $live === SiteKitContent::emptyTableSignature() ? null : $live;
    }

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

    /**
     * One log line per item that needs the reader's attention, after summary().
     *
     * @return string[]
     */
    public static function reportLines(array $report): array
    {
        $lines = [];
        foreach ($report['kept'] ?? [] as $item) {
            $lines[] = "  kept your version: {$item}";
        }
        foreach ($report['removed'] ?? [] as $item) {
            $lines[] = "  removed (no longer in this version, unchanged here): {$item}";
        }
        foreach ($report['deletedHere'] ?? [] as $item) {
            $lines[] = "  deleted on this site, not recreated: {$item}";
        }
        foreach ($report['notes'] ?? [] as $item) {
            $lines[] = "  note: {$item}";
        }

        return $lines;
    }

    public static function summary(array $report): string
    {
        return sprintf('%d updated, %d added, %d kept (edited here), %d removed, %d unchanged',
            count($report['updated']), count($report['added']), count($report['kept']),
            count($report['trashed']) + count($report['removed'] ?? []), $report['unchanged'])
            . (!empty($report['deletedHere']) ? sprintf(', %d deleted here (not recreated)', count($report['deletedHere'])) : '');
    }
}
