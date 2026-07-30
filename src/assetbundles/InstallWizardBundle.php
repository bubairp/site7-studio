<?php

namespace site7\studio\assetbundles;

use craft\web\AssetBundle;

/**
 * Fresh-Install Setup Wizard asset bundle (Website Starter Kit System
 * Phase 7). install-wizard.js only ever calls the wizard's own controller
 * actions (validate/execute/progress) - it holds no installation logic.
 */
class InstallWizardBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@site7/studio/resources';

        $this->depends = [
            Site7StudioBundle::class,
        ];

        $this->js = [
            'js/install-wizard.js',
        ];

        $this->css = [
            'css/install-wizard.css',
        ];

        parent::init();
    }
}
