<?php

namespace site7\studio\controllers;

use craft\web\Controller;

class DefaultController extends Controller
{
    public function actionIndex()
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\Site7StudioBundle::class);
        $settings = \site7\studio\Site7Studio::getInstance()->getSettings();
        $isSetupComplete = !empty($settings->matrixFieldId);

        return $this->renderTemplate('site7-studio/index', [
            'title' => 'Dashboard',
            'isSetupComplete' => $isSetupComplete,
        ]);
    }
}
