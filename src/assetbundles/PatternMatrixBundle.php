<?php

namespace site7\studio\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\assets\matrix\MatrixAsset;

class PatternMatrixBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@site7/studio/resources';

        $this->depends = [
            CpAsset::class,
            MatrixAsset::class,
        ];

        $this->js = [
            'js/pattern-browser.js',
            'js/pattern-matrix.js',
        ];

        parent::init();
    }
}
