<?php

namespace site7\studio\console\controllers;

use Craft;
use craft\console\Controller;
use site7\studio\services\library\LibraryDistribution;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Library packages through Commerce24 (docs/52_LIBRARY_DISTRIBUTION.md).
 */
class LibraryController extends Controller
{
    /**
     * Publishes Library packages to Commerce24, each as its own archive.
     * Usage: php craft site7-studio/library/publish [handle,handle...]   (default: the whole Library)
     */
    public function actionPublish(array $handles = []): int
    {
        $result = (new LibraryDistribution())->publish($handles, fn(string $line) => $this->stdout("  {$line}\n"));
        $this->stdout('Published ' . count($result['published']) . " packages\n", Console::FG_GREEN);
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
     * Downloads a package and everything it requires into this site's Library, without installing.
     * Usage: php craft site7-studio/library/download rp-craft-starter-kit
     */
    public function actionDownload(string $handle): int
    {
        $distribution = new LibraryDistribution();
        $closure = LibraryDistribution::closure($handle, $distribution->catalog());
        if ($closure['missing']) {
            $this->stderr('Not in the Commerce24 catalog: ' . implode(', ', $closure['missing']) . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $errors = $distribution->download($closure['handles'], fn(string $line) => $this->stdout("  {$line}\n"));
        foreach ($errors as $error) {
            $this->stderr("Error: {$error}\n", Console::FG_RED);
        }

        return $errors ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
