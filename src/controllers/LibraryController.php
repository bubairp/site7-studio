<?php

namespace site7\studio\controllers;

use Craft;
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

        // Check if a preview image exists
        $hasPreviewImage = false;
        if ($packagePath) {
            $previewImagePath = $packagePath . '/preview/preview.png';
            $hasPreviewImage = file_exists($previewImagePath);
        }

        return $this->renderTemplate('site7-studio/library/preview', [
            'title' => 'Preview: ' . $package->name,
            'package' => $package,
            'hasPreviewImage' => $hasPreviewImage,
        ]);
    }

    /**
     * Serves a package preview image directly.
     */
    public function actionPreviewImage(string $handle)
    {
        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($handle);
        if (!$packagePath) {
            throw new \yii\web\NotFoundHttpException("Package not found");
        }

        $imagePath = $packagePath . '/preview/preview.png';
        if (!file_exists($imagePath)) {
            throw new \yii\web\NotFoundHttpException("Preview image not found");
        }

        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Cache-Control', 'public, max-age=86400');
        $response->format = \yii\web\Response::FORMAT_RAW;
        $response->data = file_get_contents($imagePath);
        return $response;
    }
}
