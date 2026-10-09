<?php

namespace site7\studio\services\theme;

use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;
use site7\studio\records\PackageRecord;
use site7\studio\services\library\LibraryUpdater;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\Site7Studio;
use Symfony\Component\Process\Process;

/**
 * Updates an installed Theme package (docs/53_LIBRARY_UPDATES.md §4) with
 * LibraryUpdater's rule:
 *   1. code files (templates, modules, frontend sources, web/assets, config,
 *      composer.json/lock) - each file through the rule;
 *   2. composer install + migrations when the Composer files changed, then
 *      plugin/install for plugins the new version adds - subprocesses;
 *   3. structure, plugin settings, CP element sources and settings content,
 *      in a new process (site7-studio/library/apply-theme): Composer may
 *      have replaced vendor/ under this one;
 *   4. the frontend build when frontend sources or templates changed.
 */
class ThemeUpdater extends ThemeInstaller
{
    public const REPORT_PREFIX = 'REPORT:';

    public function updateTheme(PackageRecord $record, string $baseline, string $dir, callable $log): array
    {
        $old = json_decode((string)file_get_contents("{$baseline}/" . ThemeBuilder::META_FILE), true) ?: [];
        $new = json_decode((string)file_get_contents("{$dir}/" . ThemeBuilder::META_FILE), true) ?: [];
        if (!\site7\studio\services\support\CraftVersion::isCompatible($new['craftVersion'] ?? null)) {
            throw new \Exception('This version of the Theme is for Craft ' . \site7\studio\services\support\CraftVersion::range((string)$new['craftVersion']) . '; this site runs Craft ' . Craft::$app->getVersion() . '.');
        }
        $root = $this->root();
        $php = App::phpExecutable() ?? 'php';
        $result = ['errors' => [], 'warnings' => []];

        // $arrived: files the Theme changed that this site now has as the new
        // version has them - written by this run, or by an earlier run that
        // failed after writing them, so the steps below are redone on a retry.
        [$report, $arrived] = $this->applyFiles("{$baseline}/files", "{$dir}/files", $root);
        $log('Code files: ' . LibraryUpdater::summary($report));

        // installPackages() copies both Composer files from the Theme, so only
        // when neither is the customer's own.
        $composerKept = (bool)array_intersect($report['kept'], ['file composer.json', 'file composer.lock']);
        if (array_intersect($arrived, ['composer.json', 'composer.lock']) && !$composerKept) {
            if (!$this->installPackages("{$dir}/files", $root, $result, $log)) {
                throw new \Exception(implode(' ', $result['errors']));
            }
        } elseif ($composerKept) {
            $report['notes'][] = 'composer.json/lock were changed on this site, so the Theme\'s new Composer packages were not installed - merge them and run composer install.';
        }
        foreach (array_diff($new['plugins'] ?? [], $old['plugins'] ?? []) as $pluginHandle) {
            if (Craft::$app->getPlugins()->isPluginInstalled($pluginHandle)) {
                continue;
            }
            if (!$this->run([$php, "{$root}/craft", 'plugin/install', $pluginHandle], $root, "plugin {$pluginHandle}", $result, $log)) {
                throw new \Exception(implode(' ', $result['errors']));
            }
            $report['added'][] = "plugin {$pluginHandle}";
        }

        $process = new Process([$php, "{$root}/craft", 'site7-studio/library/apply-theme', $record->handle, $baseline], $root);
        $process->setTimeout(1200);
        $process->run();
        $applied = null;
        foreach (explode("\n", $process->getOutput()) as $line) {
            if (str_starts_with($line, self::REPORT_PREFIX)) {
                $applied = json_decode(substr($line, strlen(self::REPORT_PREFIX)), true);
            }
        }
        if (!$process->isSuccessful() || !is_array($applied)) {
            throw new \Exception('Applying the structure and settings failed: ' . substr(trim($process->getErrorOutput() ?: $process->getOutput()), -1500));
        }
        $log('Structure, plugin settings and settings content: ' . LibraryUpdater::summary($applied));
        $report = LibraryUpdater::mergeReports($report, $applied);

        // A Theme that ships its built frontend updates it through the file
        // rule above; only one without it is rebuilt here.
        $rebuild = empty($new['builtFrontend']) && array_filter($arrived, fn($path) => preg_match('#^(frontend|templates)/#', $path));
        if ($rebuild) {
            $this->buildFrontend($root, $result, $log);
            $report['notes'] = array_merge($report['notes'], $result['warnings']);
        }

        return $report;
    }

    /**
     * Every code file the installed or the new version ships, mapped
     * files/<path> -> <site root>/<path> as ThemeInstaller copied it.
     *
     * @return array{0: array, 1: string[]} report, and the paths the Theme
     *   changed that this site now has as the new version has them
     */
    public function applyFiles(string $baselineFiles, string $newFiles, string $root): array
    {
        $report = LibraryUpdater::emptyReport();
        $changed = [];
        foreach (array_unique(array_merge(self::relativeFiles($baselineFiles), self::relativeFiles($newFiles))) as $path) {
            if (str_starts_with($path, 'config/project/')) {
                continue;
            }
            $target = "{$root}/{$path}";
            $source = "{$newFiles}/{$path}";
            // The Tailwind safelist only grows: this site's list (its own
            // blocks merged in) plus the new Theme's, never replaced (docs/59).
            if (basename($path) === TailwindSafelist::FILE) {
                $incomingList = is_file($source) ? TailwindSafelist::parseList((string)file_get_contents($source)) : [];
                $liveList = is_file($target) ? TailwindSafelist::parseList((string)file_get_contents($target)) : [];
                if (array_diff($incomingList, $liveList)) {
                    FileHelper::createDirectory(dirname($target));
                    file_put_contents($target, TailwindSafelist::render(array_merge($liveList, $incomingList)));
                    $report[$liveList ? 'updated' : 'added'][] = "file {$path} (merged)";
                    $changed[] = $path;
                } else {
                    $report['unchanged']++;
                }
                continue;
            }
            [$base, $live, $incoming] = ["{$baselineFiles}/{$path}", $target, $source];
            // The site's composer files hold its own Site7 Studio, the Theme's
            // don't (docs/49 §2d): compare all three without it.
            if (in_array($path, ['composer.json', 'composer.lock'], true)) {
                [$base, $live, $incoming] = [self::withoutSite7($baselineFiles, $path), self::withoutSite7($root, $path), self::withoutSite7($newFiles, $path)];
            }
            if (!is_file($incoming)) {
                // Gone from the Theme: removed here too, unless edited here.
                if (is_file($base) && is_file($live)) {
                    if (PackageArchiveHelper::computeFileChecksum($live) === PackageArchiveHelper::computeFileChecksum($base)) {
                        unlink($target);
                        $report['trashed'][] = "file {$path}";
                        $changed[] = $path;
                    } else {
                        $report['kept'][] = "file {$path} (removed from the Theme, edited here)";
                    }
                }
                continue;
            }
            $decision = LibraryUpdater::decideFile($base, $live, $incoming);
            if ($decision === 'apply') {
                $report[is_file($target) ? 'updated' : 'added'][] = "file {$path}";
                FileHelper::createDirectory(dirname($target));
                copy($source, $target);
                $changed[] = $path;
            } elseif ($decision === 'kept') {
                $report['kept'][] = "file {$path}";
            } else {
                $report['unchanged']++;
                // Changed upstream and already here (an earlier, failed run).
                $incomingSum = PackageArchiveHelper::computeFileChecksum($incoming);
                if ($incomingSum !== PackageArchiveHelper::computeFileChecksum($base) && $incomingSum === PackageArchiveHelper::computeFileChecksum($live)) {
                    $changed[] = $path;
                }
            }
        }

        return [$report, $changed];
    }

    /**
     * The new process of updateTheme(): structure, plugin settings, element
     * sources and settings content through the rule.
     */
    public function applyStructureAndContent(string $handle, string $baseline): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($handle) ?? throw new \Exception("Package '{$handle}' was not found.");
        $dir = (string)$packageManager->getPackagePath($handle);
        $updater = new LibraryUpdater();
        $schemaService = new ThemeSchemaService();

        // Optional sections this site doesn't have stay away (docs/49 §2c),
        // with their settings content.
        $oldMeta = json_decode((string)file_get_contents("{$baseline}/" . ThemeBuilder::META_FILE), true) ?: [];
        $meta = json_decode((string)file_get_contents("{$dir}/" . ThemeBuilder::META_FILE), true) ?: [];
        $absent = array_values(array_filter(
            array_unique(array_merge($oldMeta['optionalSections'] ?? [], $meta['optionalSections'] ?? [])),
            fn($sectionHandle) => Craft::$app->getEntries()->getSectionByHandle($sectionHandle) === null
        ));
        $skipIds = [];
        foreach ($absent as $sectionHandle) {
            $skipIds = array_merge($skipIds, $oldMeta['sectionContent'][$sectionHandle] ?? [], $meta['sectionContent'][$sectionHandle] ?? []);
        }
        $oldSchema = $schemaService->forThisSite(ThemeSchemaService::withoutSections(json_decode((string)file_get_contents("{$baseline}/" . ThemeSchemaService::FILE), true), $absent));
        $newSchema = $schemaService->forThisSite(ThemeSchemaService::withoutSections(json_decode((string)file_get_contents("{$dir}/" . ThemeSchemaService::FILE), true), $absent));
        $oldItems = array_column($oldSchema['items'], 'config', 'path');
        $newItems = array_map(fn($item) => ['path' => $item['path'], 'label' => $item['path'] . " ({$item['handle']})", 'config' => $item['config']], $newSchema['items']);
        // The page-builder field ships without blocks; Section packages link
        // theirs in, so this site's version always differs.
        $structure = $updater->applyItems($oldItems, $newItems, $record, ["fields.{$newSchema['pageBuilderField']}"]);

        $oldExtras = $this->extras($baseline, $oldSchema, $schemaService);
        $newExtras = $this->extras($dir, $newSchema, $schemaService);
        $settings = $updater->applyItems($oldExtras, array_map(
            fn($path) => ['path' => $path, 'label' => $path, 'config' => $newExtras[$path]],
            array_keys($newExtras)
        ), $record);

        $sectionUids = [];
        foreach ($meta['settingsSections'] ?? [] as $sectionHandle) {
            if ($section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle)) {
                $sectionUids[] = $section->uid;
            }
        }
        $content = $updater->applyContent($baseline, $dir, ['sectionUids' => $sectionUids], ThemeBuilder::SETTINGS_PLUGIN_TABLES, array_unique($skipIds));

        return LibraryUpdater::mergeReports($structure, $settings, $content);
    }

    /**
     * Plugin settings (of plugins installed here) and CP element sources,
     * as project config path => config.
     */
    private function extras(string $packageDir, array $schema, ThemeSchemaService $schemaService): array
    {
        $plugins = $schemaService->forThisSite(['sourceSites' => $schema['sourceSites'] ?? [], 'sourceSiteGroups' => $schema['sourceSiteGroups'] ?? []]
            + (json_decode((string)@file_get_contents("{$packageDir}/" . ThemeBuilder::PLUGINS_FILE), true) ?: []));
        $projectConfig = Craft::$app->getProjectConfig();
        $items = [];
        foreach ($plugins['plugins'] ?? [] as $pluginHandle => $config) {
            if (isset($config['settings']) && $projectConfig->get("plugins.{$pluginHandle}") !== null) {
                $items["plugins.{$pluginHandle}.settings"] = $config['settings'];
            }
        }
        if (!empty($plugins['elementSources'])) {
            $items['elementSources'] = $plugins['elementSources'];
        }

        return $items;
    }

    /**
     * A temporary copy of $dir/$file (composer.json or composer.lock)
     * without Site7 Studio, for comparing; the original when it can't be read.
     */
    private static function withoutSite7(string $dir, string $file): string
    {
        $json = json_decode((string)@file_get_contents("{$dir}/composer.json"), true);
        $lock = json_decode((string)@file_get_contents("{$dir}/composer.lock"), true);
        if (!is_array($json) || !is_array($lock)) {
            return "{$dir}/{$file}";
        }
        [$json, $lock] = \site7\studio\services\sitekit\ComposerFiles::withoutPackage($json, $lock, \site7\studio\services\sitekit\ComposerFiles::SITE7_PACKAGE);
        $temp = Craft::$app->getRuntimePath() . '/site7-theme-compare-' . md5($dir) . "-{$file}";
        file_put_contents($temp, \site7\studio\services\sitekit\ComposerFiles::encode($file === 'composer.json' ? $json : $lock));

        return $temp;
    }

    /** @return string[] paths relative to $dir */
    private static function relativeFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        return array_map(
            fn($file) => ltrim(str_replace('\\', '/', substr($file, strlen($dir))), '/'),
            FileHelper::findFiles($dir)
        );
    }
}
