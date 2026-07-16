<?php

namespace site7\studio\controllers;

use craft\web\Controller;

class SettingsController extends Controller
{
    public function actionIndex()
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\SettingsBundle::class);
        
        return $this->renderTemplate('site7-studio/settings', [
            'title' => 'Settings',
        ]);
    }
}
