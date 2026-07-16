<?php

namespace site7\studio\assetbundles;

use craft\web\AssetBundle;

/**
 * Settings page asset bundle.
 */
class SettingsBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@site7/studio/resources';

        $this->depends = [
            Site7StudioBundle::class,
        ];

        $this->js = [
            'js/settings.js',
        ];

        parent::init();
    }
}
