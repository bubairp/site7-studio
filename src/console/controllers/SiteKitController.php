<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\Site7Studio;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Full Site Kit (docs/48_FULL_SITE_KIT.md): build on the source site,
 * validate/install on a fresh Craft install.
 */
class SiteKitController extends Controller
{
    /**
     * Builds a Full Site Kit from this site.
     * Usage: php craft site7-studio/site-kit/build "RP Craft"
     */
    public function actionBuild(string $name): int
    {
        $result = Site7Studio::getInstance()->siteKitBuilder->build($name);
        $manifest = $result['manifest'];

        $this->stdout("Built {$result['path']}\n", Console::FG_GREEN);
        $this->stdout("Craft {$manifest['craftVersion']}, " . count($manifest['plugins']) . " plugins, config files: " . implode(', ', $manifest['configFiles']) . "\n");
        foreach ($manifest['fileCounts'] as $directory => $count) {
            $this->stdout("  {$directory}: {$count} files\n");
        }
        if ($manifest['removedStalePlugins']) {
            $this->stdout('Left out plugins listed in project config but not installed: ' . implode(', ', $manifest['removedStalePlugins']) . "\n", Console::FG_YELLOW);
        }
        if ($manifest['pathRepositories']) {
            $this->stdout('Local package folders the target must also have (or publish to Git): ' . implode(', ', $manifest['pathRepositories']) . "\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Checks a kit against this site without changing anything.
     * Usage: php craft site7-studio/site-kit/validate path/to/kit.zip
     */
    public function actionValidate(string $path): int
    {
        $result = Site7Studio::getInstance()->siteKitInstaller->validateKit($path);
        return $this->report($result, 'Valid - ready to install.');
    }

    /**
     * Installs a kit onto this fresh site.
     * Usage: php craft site7-studio/site-kit/install path/to/kit.zip
     */
    public function actionInstall(string $path): int
    {
        $result = Site7Studio::getInstance()->siteKitInstaller->install($path, fn(string $line) => $this->stdout("  {$line}\n"));
        if (!empty($result['backup'])) {
            $this->stdout("Backup of the replaced files: {$result['backup']}\n");
        }

        return $this->report($result, 'Installed.');
    }

    private function report(array $result, string $success): int
    {
        foreach ($result['warnings'] as $warning) {
            $this->stdout("Warning: {$warning}\n", Console::FG_YELLOW);
        }
        foreach ($result['errors'] as $error) {
            $this->stderr("Error: {$error}\n", Console::FG_RED);
        }
        if ($result['errors']) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("{$success}\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
