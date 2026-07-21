<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\Site7Studio;
use site7\studio\services\PatternInsertionService;
use yii\console\ExitCode;

class PatternTestController extends Controller
{
    public function actionIndex()
    {
        $manager = Site7Studio::getInstance()->packageManager;
        $insertion = new PatternInsertionService();

        echo "Discovering packages...\n";
        $manager->discoverPackages();

        echo "Installing Pattern: about-company...\n";
        $installed = $manager->installPackage('about-company');
        echo "Installation result: " . ($installed ? "SUCCESS" : "FAILED") . "\n";

        echo "Enabling Pattern: about-company...\n";
        $enabled = $manager->enablePackage('about-company');
        echo "Enable result: " . ($enabled ? "SUCCESS" : "FAILED") . "\n";

        echo "Getting Pattern Blocks for about-company...\n";
        $blocks = $insertion->getPatternBlocks('about-company');

        echo "Found " . count($blocks) . " blocks.\n";

        echo "Testing Browser Data API Logic...\n";
        $allPackages = Site7Studio::getInstance()->packageManager->getAllPackages();
        $patterns = array_filter($allPackages, function($p) {
            return strtolower($p->type) === 'pattern';
        });
        echo "Found " . count($patterns) . " patterns for browser.\n";
        foreach ($patterns as $p) {
            echo " - {$p->name} ({$p->handle})\n";
            echo "   Category: " . ($p->category ?? 'None') . "\n";
            echo "   Requires: " . json_encode($p->getManifest()->requires ?? []) . "\n";
        }

        return ExitCode::OK;
    }
}
