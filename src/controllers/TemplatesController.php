<?php

namespace site7\studio\controllers;

use craft\web\Controller;

class TemplatesController extends Controller
{
    public function actionIndex()
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\Site7StudioBundle::class);
        
        return $this->renderTemplate('site7-studio/templates/index', [
            'title' => 'Templates',
        ]);
    }
}
