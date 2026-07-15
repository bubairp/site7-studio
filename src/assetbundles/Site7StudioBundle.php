<?php

namespace site7\studio\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Site7 Studio AssetBundle
 */
class Site7StudioBundle extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        // define the path that your publishable resources live
        $this->sourcePath = '@site7/studio/assetbundles/dist';

        // define the dependencies
        $this->depends = [
            CpAsset::class,
        ];

        // define the relative path to CSS/JS files that should be registered with the page
        // when this asset bundle is registered
        $this->js = [
            'js/Site7Studio.js',
        ];

        $this->css = [
            'css/Site7Studio.css',
        ];

        parent::init();
    }
}
