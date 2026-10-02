<?php
namespace modules\theme\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;

class CssController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function actionIndex(): Response
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'text/css');

        $cache = Craft::$app->getCache();
        $cacheKey = 'dynamic-css-output';

        // Try to fetch from cache
        $css = $cache->get($cacheKey);

        if ($css === false) {
            // Render CSS as pure string
            $css = Craft::$app->getView()->renderTemplate('_dynamic-css');

            // ---------------------------
            // REAL CSS MINIFICATION
            // ---------------------------

            // 1. Remove comments
            $css = preg_replace('!/\*.*?\*/!s', '', $css);

            // 2. Remove space around symbols
            $css = preg_replace('/\s*([{}|:;,])\s+/', '$1', $css);

            // 3. Remove extra semicolons
            $css = preg_replace('/;+\}/', '}', $css);

            // 4. Collapse whitespace
            $css = preg_replace('/\s+/', ' ', $css);

            $css = trim($css);

            // Cache for 30 days, or until manually cleared
            // We use a dependencyTag so we can clear it if needed
            // But generic 'entries' tag is too broad. Ideally specific dependency.
            // For now, simple time-based cache.
            $cache->set($cacheKey, $css, 2592000);
        }

        $response->content = $css;
        return $response;
    }
}
