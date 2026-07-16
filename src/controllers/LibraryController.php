<?php

namespace site7\studio\controllers;

use craft\web\Controller;
use site7\studio\Site7Studio;

class LibraryController extends Controller
{
    public function actionIndex()
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\LibraryBundle::class);
        
        $packages = Site7Studio::getInstance()->packageManager->getAllPackages();
        
        $settings = \site7\studio\Site7Studio::getInstance()->getSettings();
        $isSetupComplete = !empty($settings->matrixFieldId);

        return $this->renderTemplate('site7-studio/library/index', [
            'title' => 'Library',
            'packages' => $packages,
            'isSetupComplete' => $isSetupComplete,
        ]);
    }

    public function actionPackage(string $handle)
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\LibraryBundle::class);
        
        $package = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        
        if (!$package) {
            throw new \yii\web\NotFoundHttpException("Package not found");
        }
        
        $settings = \site7\studio\Site7Studio::getInstance()->getSettings();
        $isSetupComplete = !empty($settings->matrixFieldId);

        $usage = Site7Studio::getInstance()->packageUsage->getUsage($handle);

        return $this->renderTemplate('site7-studio/library/package', [
            'title' => $package->name,
            'package' => $package,
            'isSetupComplete' => $isSetupComplete,
            'usage' => $usage,
        ]);
    }

    public function actionPreview(string $handle)
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\LibraryBundle::class);
        
        $package = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        
        if (!$package) {
            throw new \yii\web\NotFoundHttpException("Package not found");
        }
        
        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($handle);
        if (!$packagePath) {
            throw new \yii\web\NotFoundHttpException("Package path not found");
        }

        $previewDataPath = $packagePath . '/preview-data.yaml';
        $previewData = [];
        if (file_exists($previewDataPath)) {
            $previewData = \Symfony\Component\Yaml\Yaml::parseFile($previewDataPath);
        }

        \Craft::$app->getView()->getTwig()->getLoader()->addPath($packagePath, 'site7Preview');

        return $this->renderTemplate('site7-studio/library/preview', [
            'title' => 'Preview: ' . $package->name,
            'package' => $package,
            'block' => $previewData,
        ]);
    }
}
