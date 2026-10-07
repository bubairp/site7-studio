<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use craft\elements\Entry;
use site7\studio\services\template\TemplateBuilder;
use site7\studio\Site7Studio;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Template packages, format v2 (docs/50_TEMPLATE_PACKAGE.md).
 */
class TemplateController extends Controller
{
    /** @var string|null Theme package the pages belong to (default: the Library's only Theme) */
    public ?string $theme = null;

    /** @var string|null build a variant of the page ("default") as its own package */
    public ?string $variant = null;

    /** @var string|null comma-separated block handles the variant leaves out */
    public ?string $withoutBlocks = null;

    public function options($actionID): array
    {
        return array_merge(
            parent::options($actionID),
            in_array($actionID, ['build', 'build-all'], true) ? ['theme'] : [],
            $actionID === 'build' ? ['variant', 'withoutBlocks'] : []
        );
    }

    /**
     * Builds a Template package from one page.
     * Usage: php craft site7-studio/template/build contact | services/web-development | 1234
     *        php craft site7-studio/template/build home --variant=default --without-blocks=services
     */
    public function actionBuild(string $page): int
    {
        $entry = $this->findPage($page);
        if (!$entry) {
            $this->stderr("No page '{$page}' (use <single section>, <section>/<slug> or an entry ID).\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $without = $this->withoutBlocks !== null ? array_values(array_filter(array_map('trim', explode(',', $this->withoutBlocks)))) : [];
        $result = (new TemplateBuilder())->build($entry, $this->theme, null, $this->variant, $without);
        $this->stdout("Built {$result['handle']}: " . json_encode($result['meta']['content']['counts']) . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Builds a Template package from every page (live entries of sections with URLs).
     * Usage: php craft site7-studio/template/build-all
     */
    public function actionBuildAll(): int
    {
        $result = (new TemplateBuilder())->buildAll($this->theme);
        $this->stdout('Built ' . count($result['built']) . " Template packages\n", Console::FG_GREEN);
        foreach ($result['errors'] as $error) {
            $this->stderr("Error: {$error}\n", Console::FG_RED);
        }

        return $result['errors'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Installs a Template package - with the Section packages it needs - onto
     * a site set up from its Theme.
     * Usage: php craft site7-studio/template/install page-contact
     */
    public function actionInstall(string $handle): int
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $packageManager->discoverPackages();
        try {
            $ok = $packageManager->installPackage($handle) && $packageManager->enablePackage($handle);
        } catch (\Throwable $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        foreach ($packageManager->getLastInstallWarnings() as $warning) {
            $this->stdout("Warning: {$warning}\n", Console::FG_YELLOW);
        }
        if (!$ok) {
            $this->stderr("'{$handle}' was not installed.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("Installed {$handle}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function findPage(string $page): ?Entry
    {
        if (ctype_digit($page)) {
            return Entry::find()->id((int)$page)->status(null)->one();
        }
        [$section, $slug] = array_pad(explode('/', $page, 2), 2, null);

        return Entry::find()->section($section)->slug($slug ?? '*')->status(null)->one();
    }
}
