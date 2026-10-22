<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\services\design\DesignBuilder;
use site7\studio\services\design\DesignLoader;
use site7\studio\services\design\DesignService;
use site7\studio\services\design\DesignInstaller;
use site7\studio\services\library\LibraryDistribution;
use site7\studio\services\library\LibraryUpdater;
use site7\studio\Site7Studio;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Switchable designs (docs/60_DESIGN_PACKAGE.md).
 */
class DesignController extends Controller
{
    /** @var int How long a preview link works, in hours */
    public int $hours = 24;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'preview-url' ? ['hours'] : []);
    }

    /**
     * Prints a link that shows the site in a design without activating it,
     * for anyone who has the link, for --hours (default 24).
     * Usage: php craft site7-studio/design/preview-url canopy [--hours=72]
     */
    public function actionPreviewUrl(string $handle, ?string $url = null): int
    {
        if (DesignLoader::validHandle($handle) === null) {
            $this->stderr("'{$handle}' isn't a design handle.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        if (!is_file(\Craft::getAlias("@root/designs/{$handle}/design.json"))) {
            $this->stderr("designs/{$handle} isn't installed on this site; the link would show the default design.\n", Console::FG_YELLOW);
        }
        $this->stdout(DesignLoader::previewUrl($handle, max(1, $this->hours) * 3600, $url) . "\n");

        return ExitCode::OK;
    }

    /**
     * Builds the Library package design-<handle> from designs/<handle> and its
     * build in web/designs/<handle> (run the Vite design build first).
     * Usage: php craft site7-studio/design/build canopy
     */
    public function actionBuild(string $handle): int
    {
        try {
            $result = (new DesignBuilder())->build($handle);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $meta = $result['meta'];
        $this->stdout("Built {$result['handle']}: " . count($meta['overrides']) . ' template overrides'
            . ($meta['theme'] ? ", on {$meta['theme']['handle']} {$meta['theme']['version']}" : '') . "\n", Console::FG_GREEN);
        foreach ($meta['overrides'] as $path => $checksum) {
            $this->stdout('  ' . ($checksum === null ? 'adds     ' : 'replaces ') . "{$path}\n");
        }
        $this->stdout("Publish it with site7-studio/library/publish.\n");

        return ExitCode::OK;
    }

    /**
     * Installs a design package: from this site's Library, or downloaded from
     * Commerce24 first (signed, plan checked). Development site only.
     * Usage: php craft site7-studio/design/install design-canopy
     */
    public function actionInstall(string $packageHandle): int
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($packageHandle);
        if (!$record) {
            $errors = (new LibraryDistribution())->downloadWithRequirements($packageHandle, fn(string $line) => $this->stdout("  {$line}\n"));
            if ($errors) {
                $this->stderr(implode("\n", $errors) . "\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $record = $packageManager->getPackageByHandle($packageHandle);
        }
        if (!$record || $record->type !== 'design') {
            $this->stderr("'{$packageHandle}' isn't a design package.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        if (!$packageManager->installPackage($packageHandle)) {
            $this->stderr(($packageManager->getLastInstallError() ?? 'Not installed.') . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        foreach ($packageManager->getLastInstallWarnings() as $warning) {
            $this->stdout("  {$warning}\n", Console::FG_YELLOW);
        }
        $this->stdout("Installed {$packageHandle} {$record->version}. Preview: site7-studio/design/preview-url " . DesignInstaller::designHandle((string)$packageManager->getPackagePath($packageHandle)) . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Moves an installed design to any published version, newer or older;
     * files edited here are kept.
     * Usage: php craft site7-studio/design/version design-canopy 0.1.0
     */
    public function actionVersion(string $packageHandle, string $version): int
    {
        $result = (new LibraryUpdater())->changeVersion($packageHandle, $version, fn(string $line) => $this->stdout("{$line}\n"));
        if ($result['errors']) {
            $this->stderr(implode("\n", $result['errors']) . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Removes an installed design (not the active one): untouched files go,
     * edited ones stay. It stays in the Library.
     * Usage: php craft site7-studio/design/remove design-canopy
     */
    public function actionRemove(string $packageHandle): int
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($packageHandle);
        if (!$record || $record->type !== 'design') {
            $this->stderr("'{$packageHandle}' isn't a design package.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $ok = $packageManager->uninstallPackage($packageHandle);
        foreach ($packageManager->getLastDeleteWarnings() as $warning) {
            $this->stdout("  {$warning}\n", $ok ? Console::FG_YELLOW : Console::FG_RED);
        }
        if (!$ok) {
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("Removed {$packageHandle}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Makes a design (or "default") the one every page renders and clears the
     * template caches. Development site only; deploy config/project afterwards.
     * Usage: php craft site7-studio/design/activate canopy
     */
    public function actionActivate(string $handle): int
    {
        try {
            (new DesignService())->activate(DesignLoader::validHandle($handle));
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(($handle === DesignLoader::DEFAULT_HANDLE ? 'The default design' : $handle) . " is active; template caches cleared.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Lists the published versions of a package on Commerce24, newest first.
     * Usage: php craft site7-studio/design/versions design-canopy
     */
    public function actionVersions(string $packageHandle): int
    {
        $versions = (new LibraryDistribution())->versions($packageHandle);
        if (!$versions) {
            $this->stdout("No published versions of {$packageHandle} (or Commerce24 isn't connected).\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }
        foreach ($versions as $v) {
            $this->stdout(sprintf("  %-10s %-26s %s\n", $v['version'], $v['publishedAt'] ?? '', $v['releaseNotes'] ?? ''));
        }

        return ExitCode::OK;
    }
}
