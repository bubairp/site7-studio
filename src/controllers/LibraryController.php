<?php

namespace site7\studio\controllers;

use craft\web\Controller;

class LibraryController extends Controller
{
    public function actionIndex()
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\LibraryBundle::class);
        
        return $this->renderTemplate('site7-studio/library/index', [
            'title' => 'Library',
        ]);
    }
}
