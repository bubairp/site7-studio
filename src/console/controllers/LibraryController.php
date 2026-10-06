<?php

namespace site7\studio\console\controllers;

use Craft;
use craft\console\Controller;
use site7\studio\services\library\LibraryDistribution;
use site7\studio\services\library\LibraryUpdater;
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

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'publish' => ['bump', 'force', 'notes'],
            'update' => ['all'],
            default => [],
        });
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
