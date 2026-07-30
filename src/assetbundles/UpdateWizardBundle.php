<?php

namespace site7\studio\assetbundles;

use craft\web\AssetBundle;

/**
 * Update Wizard asset bundle (Website Starter Kit System Phase 8).
 * update-wizard.js only ever calls the wizard's own controller actions - it
 * holds no diff/execution logic.
 */
class UpdateWizardBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@site7/studio/resources';

        $this->depends = [
            InstallWizardBundle::class,
        ];

        $this->js = [
            'js/update-wizard.js',
        ];

        parent::init();
    }
}
