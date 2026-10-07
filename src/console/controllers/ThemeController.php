<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\services\theme\ThemeBuilder;
use site7\studio\services\theme\ThemeInstaller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Theme packages (docs/49_THEME_PACKAGE.md).
 */
class ThemeController extends Controller
{
    /** @var string|null comma-separated handles of the sections every site gets; the rest install with the pages that need them */
    public ?string $baseSections = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'build' ? ['baseSections'] : []);
    }

    /**
     * Builds a Theme package from this site into the Library.
     * Usage: php craft site7-studio/theme/build "RP Craft Theme" [--base-sections=home,contact,header,...]
     */
    public function actionBuild(string $name): int
    {
        $base = $this->baseSections !== null ? array_values(array_filter(array_map('trim', explode(',', $this->baseSections)))) : null;
        $result = (new ThemeBuilder())->build($name, null, $base);
        $meta = $result['meta'];

        $this->stdout("Built {$result['path']}\n", Console::FG_GREEN);
        $this->stdout("Craft {$meta['craftVersion']}, " . count($meta['plugins']) . " plugins\n");
        $this->stdout('Structure: ' . json_encode($meta['structure']) . "\n");
        $this->stdout("Page builder '{$meta['pageBuilderField']}' without its {$meta['pageBuilderBlocks']} blocks (they're Section packages)\n");
        $this->stdout('Settings content: ' . implode(', ', $meta['settingsSections']) . ' - ' . json_encode($meta['content']['counts'] ?? []) . "\n");
        if ($meta['optionalSections']) {
            $this->stdout('Optional sections (installed with the pages that need them): ' . implode(', ', $meta['optionalSections']) . "\n");
        }

        return ExitCode::OK;
    }

    /**
     * Usage: php craft site7-studio/theme/validate <handle>
     */
    public function actionValidate(string $handle): int
    {
        return $this->report((new ThemeInstaller())->validateTheme($handle), 'Valid - ready to install.');
    }

    /**
     * Installs a Theme package from this site's Library onto this fresh site.
     * Usage: php craft site7-studio/theme/install <handle>
     */
    public function actionInstall(string $handle): int
    {
        $result = (new ThemeInstaller())->installTheme($handle, fn(string $line) => $this->stdout("  {$line}\n"));

        return $this->report($result, 'Theme installed.');
    }

    /**
     * Internal: the structure step of theme/install, run as its own process.
     */
    public function actionApply(string $handle): int
    {
        $result = (new ThemeInstaller())->apply($handle);
        $this->stdout("Structure: created {$result['created']}, reused {$result['reused']}\n");

        return ExitCode::OK;
    }

    /**
     * Internal: the settings content step of theme/install - the base part
     * when the Theme has optional sections.
     */
    public function actionImportContent(string $handle): int
    {
        $counts = (new ThemeInstaller())->importContent($handle);
        $this->stdout('Imported: ' . json_encode($counts) . "\n");

        return ExitCode::OK;
    }

    /**
     * Adds a Theme's optional sections to this site, with their settings content.
     * Usage: php craft site7-studio/theme/add-sections blogs,blogCategories
     */
    public function actionAddSections(string $handles): int
    {
        $added = (new ThemeInstaller())->addSections(array_filter(array_map('trim', explode(',', $handles))));
        $this->stdout('Added: ' . ($added ? implode(', ', $added) : 'nothing - already here or not optional') . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Internal: every Single gets exactly one entry with its URL (theme/install step).
     */
    public function actionEnsureSingles(): int
    {
        $handles = (new ThemeInstaller())->ensureSingles();
        $this->stdout('Singles ready: ' . implode(', ', $handles) . "\n");

        return ExitCode::OK;
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
