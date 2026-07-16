<?php

namespace site7\studio\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Site7 Studio Asset Bundle
 *
 * Provides the core custom CSS for Site7 Studio.
 * Only includes styles for patterns that Craft CMS does not provide natively
 * (card grids, preview cards, stat cards, status badges).
 */
class Site7StudioBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@site7/studio/resources';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/app.css',
        ];

        parent::init();
    }
}
