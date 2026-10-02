<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\services\starterkit\KitBuilder;
use site7\studio\services\starterkit\KitInstaller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Library Starter Kits, format v2 (docs/51_LIBRARY_STARTER_KIT.md).
 * The blueprint Starter Kits (docs/32) use make/starter-kit and the CP wizard.
 */
class StarterKitController extends Controller
{
    /** @var string|null Theme package (default: the Library's only Theme) */
    public ?string $theme = null;

    /** @var bool rebuild every page's Template package first */
    public bool $templates = true;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'build' ? ['theme', 'templates'] : []);
    }

    /**
     * Builds a Library Starter Kit from this site.
     * Usage: php craft site7-studio/starter-kit/build "RP Craft" [--templates=0]
     */
    public function actionBuild(string $name): int
    {
        $result = (new KitBuilder())->build($name, $this->theme, $this->templates);
        $meta = $result['meta'];
        $this->stdout("Built {$result['handle']}: Theme {$meta['theme']}, {$meta['templates']} pages, " . json_encode($meta['content']['counts']) . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Usage: php craft site7-studio/starter-kit/validate <handle>
     */
    public function actionValidate(string $handle): int
    {
        $result = (new KitInstaller())->validateKit($handle);
        if (!$result['errors']) {
            $this->stdout(($result['themeInstalled'] ? "Theme {$result['theme']} is installed." : "Installs the Theme {$result['theme']} first.") . "\n");
        }

        return $this->report($result, 'Valid - ready to install.');
    }

    /**
     * Installs a Library Starter Kit onto this fresh site (Theme, pages, menus, demo content).
     * Usage: php craft site7-studio/starter-kit/install <handle>
     */
    public function actionInstall(string $handle): int
    {
        $result = (new KitInstaller())->installKit($handle, fn(string $line) => $this->stdout("  {$line}\n"));

        return $this->report($result, 'Starter Kit installed.');
    }

    /**
     * Internal: the kit step of starter-kit/install, run as its own process.
     */
    public function actionApply(string $handle): int
    {
        $errors = (new KitInstaller())->applyKit($handle);

        return $this->report(['errors' => $errors, 'warnings' => []], "Installed {$handle}.");
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
