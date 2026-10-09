<?php

namespace site7\studio\console\controllers;

use Craft;
use craft\console\Controller;
use site7\studio\services\theme\TailwindSafelist;
use site7\studio\Site7Studio;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * The frontend's Tailwind build and the Library blocks (docs/59).
 */
class FrontendController extends Controller
{
    /**
     * Lists, per installed Section package, the Tailwind classes of its
     * _blocks template that the built CSS doesn't have.
     * Usage: php craft site7-studio/frontend/check
     */
    public function actionCheck(): int
    {
        $missing = TailwindSafelist::missingByBlock(rtrim(str_replace('\\', '/', (string)Craft::getAlias('@root')), '/'));
        if ($missing === null) {
            $this->stderr("No built frontend (Vite manifest) found on this site.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $missingBlocks = count($missing);
        foreach ($missing as $name => $classes) {
            $this->stdout(sprintf("  %-32s %3d missing: %s\n", $name, count($classes), implode(' ', array_slice($classes, 0, 10)) . (count($classes) > 10 ? ' ...' : '')), Console::FG_YELLOW);
        }
        if ($missingBlocks === 0) {
            $this->stdout("Every installed block's classes are in the built CSS.\n", Console::FG_GREEN);
        } else {
            $this->stdout("{$missingBlocks} blocks miss styles: run `npm run build` in frontend/.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Writes the safelist for a site set up before it existed: the classes of
     * the installed blocks and of every Section package in this Library.
     * Usage: php craft site7-studio/frontend/safelist
     */
    public function actionSafelist(): int
    {
        $classes = TailwindSafelist::libraryClasses();
        foreach (TailwindSafelist::installedBlockTemplates() as $template) {
            array_push($classes, ...TailwindSafelist::extractClasses((string)file_get_contents($template)));
        }
        $result = TailwindSafelist::merge(rtrim(str_replace('\\', '/', (string)Craft::getAlias('@root')), '/'), $classes);
        if ($result === null) {
            $this->stderr("No Tailwind entry stylesheet (an app.css importing tailwindcss) under frontend/.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(count($result['added']) . " classes added to {$result['path']}. Run `npm run build` in frontend/ to apply them.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
