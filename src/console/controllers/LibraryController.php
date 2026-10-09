<?php

namespace site7\studio\console\controllers;

use Craft;
use craft\console\Controller;
use site7\studio\services\library\LibraryDistribution;
use site7\studio\services\library\LibraryReconciler;
use site7\studio\services\library\LibraryUpdater;
use site7\studio\services\library\TrackingSnapshot;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Library packages through Commerce24 (docs/52_LIBRARY_DISTRIBUTION.md).
 */
class LibraryController extends Controller
{
    /** @var string patch|minor|major - how changed packages' versions are raised */
    public string $bump = 'patch';

    /** @var bool publish unchanged packages too */
    public bool $force = false;

    /** @var string|null release notes for this publish */
    public ?string $notes = null;

    /** @var bool update every Library package that has an update */
    public bool $all = false;

    /** @var bool report what reconcile/restore would do, change nothing */
    public bool $dryRun = false;

    /** @var bool reconcile also matches Themes, Templates and Starter Kits (customer sites only, not the author site) */
    public bool $siteContent = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'publish' => ['bump', 'force', 'notes'],
            'update' => ['all'],
            'reconcile' => ['dryRun', 'siteContent'],
            'restore' => ['dryRun'],
            default => [],
        });
    }

    /**
     * Marks Library packages whose blocks are already on this site as
     * installed, and adds their missing file baselines - for a plugin
     * reinstalled without an uninstall snapshot (docs/58).
     * --site-content also matches Themes, Templates and Starter Kits: only on a site that installed them from the Library, never the author site.
     * Usage: php craft site7-studio/library/reconcile [--dry-run] [--site-content]
     */
    public function actionReconcile(): int
    {
        $report = (new LibraryReconciler())->reconcile($this->dryRun, $this->siteContent);
        $verb = $this->dryRun ? 'Would mark' : 'Marked';
        if ($report['matrixField'] !== null) {
            $this->stdout(($this->dryRun ? 'Would set' : 'Set') . " the page builder field: {$report['matrixField']}\n");
        }
        foreach ($report['packages'] as $handle => $change) {
            $this->stdout(sprintf("  %-40s %s -> %s  (%s)\n", $handle, $change['from'], $change['to'], implode(', ', $change['blocks'])));
        }
        foreach ($report['baselines'] as $targetPath => $note) {
            $this->stdout("  baseline {$targetPath}: {$note}\n");
        }
        foreach ($report['notes'] as $note) {
            $this->stdout("  Note: {$note}\n", Console::FG_YELLOW);
        }
        $this->stdout("{$verb} " . count($report['packages']) . ' packages installed, ' . count($report['baselines']) . " baselines added\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Writes the tracking snapshot an uninstall writes (storage/site7-studio/uninstall-snapshot.json), without uninstalling.
     * Usage: php craft site7-studio/library/snapshot
     */
    public function actionSnapshot(): int
    {
        $path = (new TrackingSnapshot())->write();
        $this->stdout("Snapshot written: {$path}\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Restores the uninstall snapshot (normally automatic on reinstall). Only fills in: existing tracking is kept.
     * Usage: php craft site7-studio/library/restore [path] [--dry-run]
     */
    public function actionRestore(?string $path = null): int
    {
        $report = (new TrackingSnapshot())->restore($this->dryRun, $path);
        if ($report['error'] !== null) {
            $this->stderr("Error: {$report['error']}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        foreach ($report['settings'] as $key => $value) {
            $this->stdout("  setting {$key}: " . json_encode($value) . "\n");
        }
        foreach (['restored', 'kept', 'skipped'] as $kind) {
            foreach ($report['packages'][$kind] as $handle => $detail) {
                $this->stdout(sprintf("  %-9s %-40s %s\n", $kind, $handle, $detail));
            }
        }
        foreach ($report['rows'] as $table => $counts) {
            $this->stdout(sprintf("  %-36s restored %d, skipped %d\n", $table, $counts['restored'], $counts['skipped']));
        }
        foreach ($report['flags'] as $flag) {
            $this->stdout("  ! {$flag}\n", Console::FG_YELLOW);
        }
        $this->stdout(($this->dryRun ? 'Dry run - nothing was changed.' : 'Restored' . ($report['renamedTo'] ? "; snapshot renamed to {$report['renamedTo']}" : '') . '.') . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Publishes the Library packages that changed since their last publish
     * to Commerce24, each as its own archive, with a raised version.
     * Usage: php craft site7-studio/library/publish [handle,handle...] [--bump=minor] [--notes="..."] [--force]
     */
    public function actionPublish(array $handles = []): int
    {
        $result = (new LibraryDistribution())->publish($handles, fn(string $line) => $this->stdout("  {$line}\n"), $this->bump, $this->force, $this->notes);
        $this->stdout('Published ' . count($result['published']) . ' packages, ' . count($result['unchanged']) . " unchanged\n", Console::FG_GREEN);
        foreach ($result['errors'] as $error) {
            $this->stderr("Error: {$error}\n", Console::FG_RED);
        }

        return $result['errors'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Renames a Library package (the name customers see); the handle stays.
     * Usage: php craft site7-studio/library/rename rp-craft-starter-kit "Site7 Full Kit"
     */
    public function actionRename(string $handle, string $name): int
    {
        (new LibraryDistribution())->setName($handle, $name);
        $this->stdout("{$handle}: name \"{$name}\". Publish it again for Commerce24 to show it.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Sets a Library package's price type (free, premium, private, enterprise).
     * Usage: php craft site7-studio/library/pricing rp-craft-starter-kit premium
     */
    public function actionPricing(string $handle, string $pricingType): int
    {
        (new LibraryDistribution())->setPricing($handle, $pricingType);
        $this->stdout("{$handle}: pricingType {$pricingType}. Publish it again for Commerce24 to sell it that way.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Lists what Commerce24 offers this site.
     * Usage: php craft site7-studio/library/catalog
     */
    public function actionCatalog(): int
    {
        $catalog = (new LibraryDistribution())->catalog();
        $counts = array_count_values(array_map(fn($entry) => $entry['type'] ?? '?', $catalog));
        $this->stdout(count($catalog) . ' packages: ' . json_encode($counts) . "\n");
        foreach ($catalog as $entry) {
            if (($entry['type'] ?? null) === 'starter-kit' || ($entry['pricingType'] ?? 'free') !== 'free') {
                $this->stdout(sprintf("  %-28s %-12s %-10s %s%s\n", $entry['handle'], $entry['type'], $entry['pricingType'] ?? 'free',
                    Craft::$app->getFormatter()->asShortSize((int)($entry['size'] ?? 0)), empty($entry['entitled']) ? '  (not entitled)' : ''));
            }
        }

        return ExitCode::OK;
    }

    /**
     * Lists installed Library packages that Commerce24 has a newer version of.
     * Usage: php craft site7-studio/library/updates
     */
    public function actionUpdates(): int
    {
        $updates = (new LibraryUpdater())->availableUpdates(true);
        $this->stdout(count($updates) . " updates available\n");
        foreach ($updates as $update) {
            $this->stdout(sprintf("  %-40s %-10s %s -> %s%s%s\n", $update['handle'], $update['type'], $update['from'], $update['to'],
                $update['supported'] ? '' : '  (not supported yet)', $update['releaseNotes'] ? "  - {$update['releaseNotes']}" : ''));
        }

        return ExitCode::OK;
    }

    /**
     * Updates installed Library packages from Commerce24, keeping whatever was edited on this site.
     * Usage: php craft site7-studio/library/update <handle,handle...> | --all
     */
    public function actionUpdate(array $handles = []): int
    {
        if (!$handles && !$this->all) {
            $this->stderr("Name the packages to update, or pass --all.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $result = (new LibraryUpdater())->update($this->all ? [] : $handles, fn(string $line) => $this->stdout("  {$line}\n"));
        $this->stdout('Updated ' . count($result['updated']) . ' packages, skipped ' . count($result['skipped']) . "\n", Console::FG_GREEN);
        foreach ($result['errors'] as $error) {
            $this->stderr("Error: {$error}\n", Console::FG_RED);
        }

        return $result['errors'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Internal: the structure/settings/content step of a Theme update, run as its own process.
     */
    public function actionApplyTheme(string $handle, string $baseline): int
    {
        $report = (new \site7\studio\services\theme\ThemeUpdater())->applyStructureAndContent($handle, $baseline);
        $this->stdout(\site7\studio\services\theme\ThemeUpdater::REPORT_PREFIX . json_encode($report) . "\n");

        return ExitCode::OK;
    }

    /**
     * Downloads a package and everything it requires into this site's Library, without installing.
     * Usage: php craft site7-studio/library/download rp-craft-starter-kit
     */
    public function actionDownload(string $handle): int
    {
        $errors = (new LibraryDistribution())->downloadWithRequirements($handle, fn(string $line) => $this->stdout("  {$line}\n"));
        foreach ($errors as $error) {
            $this->stderr("Error: {$error}\n", Console::FG_RED);
        }

        return $errors ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
