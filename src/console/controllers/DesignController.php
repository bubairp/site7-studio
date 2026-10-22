<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\services\design\DesignBuilder;
use site7\studio\services\design\DesignLoader;
use site7\studio\services\library\LibraryDistribution;
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
        if (!is_dir(\Craft::getAlias("@root/designs/{$handle}/templates"))) {
            $this->stderr("designs/{$handle}/templates doesn't exist on this site; the link would show the default design.\n", Console::FG_YELLOW);
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
