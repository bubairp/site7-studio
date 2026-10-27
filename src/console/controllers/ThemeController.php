<?php

namespace site7\studio\console\controllers;

use Craft;
use craft\console\Controller;
use site7\studio\services\theme\ThemeBuilder;
use site7\studio\services\theme\ThemeInstaller;
use site7\studio\services\theme\ThemeMigration;
use site7\studio\services\theme\ThemeOverrides;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Theme packages (docs/49_THEME_PACKAGE.md).
 */
class ThemeController extends Controller
{
    /** @var string|null comma-separated handles of the sections every site gets; the rest install with the pages that need them */
    public ?string $baseSections = null;

    /** @var bool theme/migrate: only report what it would do */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'build' => ['baseSections'],
            'migrate' => ['dryRun'],
            default => [],
        });
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

    /**
     * Copies a template of the base theme (site7/theme) into templates/ to
     * change it on this site, and records which theme version it came from.
     * Usage: php craft site7-studio/theme/override _blocks/ctaBanner.twig
     */
    public function actionOverride(string $template): int
    {
        try {
            $path = ThemeOverrides::forSite()->copy($template);
        } catch (\Exception $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("Copied to templates/{$path}: edit it there, this site now renders it instead of the theme's.\n", Console::FG_GREEN);
        $this->stdout("Recorded in templates/" . ThemeOverrides::LEDGER . " (commit both). theme/overrides lists theme changes your copy hides.\n");

        return ExitCode::OK;
    }

    /**
     * This site's overrides of the base theme, and which of them the theme
     * changed since they were copied.
     * Usage: php craft site7-studio/theme/overrides
     */
    public function actionOverrides(): int
    {
        $overrides = ThemeOverrides::forSite();
        $report = $overrides->report();
        if (ThemeOverrides::installedThemeVersion() === null) {
            $this->stdout("This site has no site7/theme package: its templates are all in templates/.\n");
            return ExitCode::OK;
        }
        if (!$report) {
            $this->stdout("No overrides: every base template comes from site7/theme " . ThemeOverrides::installedThemeVersion() . ".\n");
            return ExitCode::OK;
        }
        $notes = [
            ThemeOverrides::CURRENT => ['current', Console::FG_GREEN],
            ThemeOverrides::CHANGED => ['the theme changed this file since it was copied: review your copy', Console::FG_YELLOW],
            ThemeOverrides::REMOVED => ['the theme no longer has this file: your copy may be unused', Console::FG_YELLOW],
            ThemeOverrides::UNTRACKED => ['not recorded (copied by hand): no base to compare with', Console::FG_GREY],
        ];
        foreach ($report as $path => $override) {
            [$note, $colour] = $notes[$override['state']];
            $from = $override['theme'] ? " (copied from {$override['theme']})" : '';
            $this->stdout(str_pad($path, 48) . " {$note}{$from}\n", $colour);
        }
        $this->stdout(count($report) . ' override(s), ' . count($overrides->needsReview()) . " to review. Theme: site7/theme " . ThemeOverrides::installedThemeVersion() . "\n");

        return ExitCode::OK;
    }

    /**
     * Moves this site onto the site7/theme package: deletes its copies of
     * theme templates that match the theme, keeps edited ones as overrides
     * (docs/60 §3). Run Library updates first. Backs up the database and
     * templates/ before changing anything.
     * Usage: php craft site7-studio/theme/migrate [--dry-run]
     */
    public function actionMigrate(): int
    {
        try {
            $migration = ThemeMigration::forSite();
            $plan = $migration->plan();
        } catch (\Exception $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $labels = [
            ThemeMigration::REMOVE => ['delete    same as the theme\'s', Console::FG_GREEN],
            ThemeMigration::WAIT => ['keep      as installed, older than the theme\'s: run Library updates, then migrate again', Console::FG_YELLOW],
            ThemeMigration::OVERRIDE => ['override  edited on this site: kept, recorded as an override', Console::FG_CYAN],
        ];
        foreach ($plan as $path => $action) {
            [$label, $colour] = $labels[$action];
            $this->stdout(str_pad($path, 48) . " {$label}\n", $colour);
        }
        $counts = array_count_values($plan) + [ThemeMigration::REMOVE => 0, ThemeMigration::WAIT => 0, ThemeMigration::OVERRIDE => 0];
        $summary = "{$counts[ThemeMigration::REMOVE]} to delete, {$counts[ThemeMigration::OVERRIDE]} to keep as overrides, {$counts[ThemeMigration::WAIT]} waiting for a Library update";
        if (!$plan) {
            $this->stdout("Nothing to migrate: templates/ has no copies of the theme's templates.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        if ($this->dryRun) {
            $this->stdout("{$summary}. Dry run - nothing was changed.\n");
            return ExitCode::OK;
        }

        $this->stdout('Backing up the database... ');
        $this->stdout(Craft::$app->getDb()->backup() . "\n");
        $copy = Craft::$app->getPath()->getDbBackupPath() . '/templates-before-theme-migrate-' . date('Ymd-His');
        \craft\helpers\FileHelper::copyDirectory(Craft::getAlias('@root') . '/templates', $copy);
        $this->stdout("Copied templates/ to {$copy}\n");

        $report = $migration->run();
        $this->stdout('Deleted ' . count($report['removed']) . ', kept ' . count($report['overrides']) . ' as overrides (templates/' . ThemeOverrides::LEDGER . '), ' . count($report['waiting']) . " waiting for a Library update.\n", Console::FG_GREEN);
        if ($report['overrides']) {
            $this->stdout("Commit templates/ (the deletions and the overrides file). php craft site7-studio/theme/overrides lists the overrides.\n");
        }

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
