<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\services\design\DesignLoader;
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
}
