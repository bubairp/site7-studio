<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\Site7Studio;
use yii\console\ExitCode;

/**
 * Package Command
 * 
 * Allows syncing Commerce24 packages from the command line.
 */
class PackageController extends Controller
{
    /**
     * Syncs packages and matrix blocks.
     * 
     * Usage: craft site7-studio/package/sync
     */
    public function actionSync(): int
    {
        $this->stdout("Starting package sync...\n");

        $defaultPackage = Site7Studio::getInstance()->getSettings()->defaultPackage;
        $success = Site7Studio::getInstance()->packageEngine->syncMatrixBlocksForPackage($defaultPackage);

        if ($success) {
            $this->stdout("Package sync completed successfully.\n");
            return ExitCode::OK;
        }

        $this->stderr("Package sync failed.\n");
        return ExitCode::UNSPECIFIED_ERROR;
    }
}
