<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\services\engine\PackageDiscovery;
use site7\studio\registries\MemoryPackageRegistry;
use site7\studio\repositories\PackageRepository;
use yii\console\ExitCode;
use Craft;

/**
 * Tests the Package Discovery service for Milestone 4.1.
 */
class TestDiscoveryController extends Controller
{
    /**
     * Runs the discovery test.
     */
    public function actionIndex()
    {
        $this->stdout("Running Milestone 4.1 Discovery Test...\n");

        $registry = new MemoryPackageRegistry();
        $discovery = new PackageDiscovery();
        $discovery->registry = $registry;
        $discovery->init();

        $path = dirname(__DIR__, 3) . '/tests/fixtures/packages';
        
        $this->stdout("Scanning path: {$path}\n");

        $count = $discovery->discoverFromPath($path);

        $this->stdout("Discovered {$count} packages.\n");

        $packages = $registry->getAllPackages();
        $repository = new PackageRepository();
        
        foreach ($packages as $package) {
            $this->stdout("- Package: {$package->manifest->name} ({$package->manifest->handle})\n");
            $this->stdout("  Type: {$package->getPackageType()}\n");
            $this->stdout("  Version: {$package->manifest->version}\n");
            
            if ($repository->save($package)) {
                $this->stdout("  => Saved to database!\n", \yii\helpers\Console::FG_GREEN);
            } else {
                $this->stdout("  => Failed to save to database.\n", \yii\helpers\Console::FG_RED);
            }
        }

        if ($count > 0) {
            $this->stdout("Test Passed!\n", \yii\helpers\Console::FG_GREEN);
            return ExitCode::OK;
        } else {
            $this->stdout("Test Failed!\n", \yii\helpers\Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}
