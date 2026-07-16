<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;

class PackageActionController extends Controller
{
    /**
     * @inheritdoc
     */
    public $defaultAction = 'install';

    /**
     * Installs a package.
     */
    public function actionInstall()
    {
        $this->requirePostRequest();
        
        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');
        $success = Site7Studio::getInstance()->packageManager->installPackage($handle);
        
        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('site7-studio', 'Package installed successfully.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Could not install package.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Enables a package.
     */
    public function actionEnable()
    {
        $this->requirePostRequest();
        
        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');
        $success = Site7Studio::getInstance()->packageManager->enablePackage($handle);
        
        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('site7-studio', 'Package enabled successfully.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Could not enable package.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Disables a package.
     */
    public function actionDisable()
    {
        $this->requirePostRequest();
        
        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');

        // Phase 7.2 Safety Check
        $usage = Site7Studio::getInstance()->packageUsage->getUsage($handle);
        if (!empty($usage)) {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Cannot disable package. It is currently in use by ' . count($usage) . ' entries.'));
            return $this->redirectToPostedUrl();
        }

        $success = Site7Studio::getInstance()->packageManager->disablePackage($handle);
        
        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('site7-studio', 'Package disabled successfully.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Could not disable package.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Removes a package.
     */
    public function actionRemove()
    {
        $this->requirePostRequest();
        
        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');

        // Phase 7.2 Safety Check
        $usage = Site7Studio::getInstance()->packageUsage->getUsage($handle);
        if (!empty($usage)) {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Cannot remove package. It is currently in use by ' . count($usage) . ' entries.'));
            return $this->redirectToPostedUrl();
        }

        $success = Site7Studio::getInstance()->packageManager->removePackage($handle);
        
        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('site7-studio', 'Package removed successfully.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Could not remove package.'));
        }

        return $this->redirectToPostedUrl();
    }
}
