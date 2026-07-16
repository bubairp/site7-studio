<?php
namespace site7\studio\console\controllers;
use yii\console\Controller;
class ClearController extends Controller {
    public function actionSettings() {
        \Craft::$app->getPlugins()->savePluginSettings(\site7\studio\Site7Studio::getInstance(), ['matrixFieldId' => null]);
        echo "Settings cleared.\n";
    }
}
