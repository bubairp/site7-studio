<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;

class DefaultController extends Controller
{
    public function actionIndex()
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\Site7StudioBundle::class);

        $settings = Site7Studio::getInstance()->getSettings();
        $isSetupComplete = !empty($settings->matrixFieldId);

        $packages = Site7Studio::getInstance()->packageManager->getAllPackages();

        $byType = ['section' => 0, 'pattern' => 0, 'template' => 0, 'starter-kit' => 0];
        $byStatus = ['available' => 0, 'installed' => 0, 'enabled' => 0, 'disabled' => 0];
        foreach ($packages as $package) {
            $type = strtolower($package->type);
            if (isset($byType[$type])) {
                $byType[$type]++;
            }
            $status = strtolower($package->status);
            if (isset($byStatus[$status])) {
                $byStatus[$status]++;
            }
        }

        $pluginInfo = Craft::$app->getPlugins()->getPluginInfo('site7-studio');

        return $this->renderTemplate('site7-studio/index', [
            'title' => 'Dashboard',
            'isSetupComplete' => $isSetupComplete,
            'totalPackages' => count($packages),
            'byType' => $byType,
            'byStatus' => $byStatus,
            'version' => $pluginInfo['version'] ?? '1.0.0',
        ]);
    }
}
