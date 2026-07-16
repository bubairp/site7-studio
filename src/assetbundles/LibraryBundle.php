<?php

namespace site7\studio\assetbundles;

use craft\web\AssetBundle;

/**
 * Library page asset bundle.
 */
class LibraryBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@site7/studio/resources';

        $this->depends = [
            Site7StudioBundle::class,
        ];

        $this->js = [
            'js/library.js',
        ];

        parent::init();
    }
}
