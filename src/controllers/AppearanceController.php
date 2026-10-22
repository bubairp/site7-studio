<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\models\Settings;
use site7\studio\services\design\DesignLoader;
use site7\studio\services\design\DesignService;
use site7\studio\services\library\LibraryDistribution;
use site7\studio\Site7Studio;
use yii\web\Response;

/**
 * Site7 Studio → Appearance (docs/60 §4c): the designs this site has or can
 * get, preview links, and switching. Installing, updating, changing version
 * and removing run as background jobs (the console commands) with the live
 * progress page, like Library updates; activating is immediate.
 */
class AppearanceController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('manageDesigns');

        return true;
    }

    public function actionIndex(): Response
    {
        $service = new DesignService();
        $designs = $service->designs();
        $versions = [];
        foreach ($designs as $design) {
            if ($design['installed'] && $design['package']) {
                $versions[$design['package']] = array_column((new LibraryDistribution())->versions($design['package']), 'version');
            }
        }

        return $this->renderTemplate('site7-studio/appearance/index', [
            'designs' => $designs,
            'versions' => $versions,
            'canChange' => Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'envOverride' => in_array('activeDesign', Settings::overriddenKeys(), true) ? (Site7Studio::getInstance()->getSettings()->activeDesign ?: 'default') : null,
        ]);
    }

    public function actionActivate(): Response
    {
        $this->requirePostRequest();
        $handle = DesignLoader::validHandle($this->request->getRequiredBodyParam('design'));
        $service = new DesignService();
        try {
            $service->activate($handle);
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());
            return $this->redirect('site7-studio/appearance');
        }
        $name = 'The default design';
        foreach ($service->designs(false) as $design) {
            if ($handle !== null && $design['handle'] === $handle) {
                $name = $design['name'];
            }
        }
        $this->setSuccessFlash("{$name} is now active. Template caches were cleared. Commit config/project and deploy to make it live on your other environments.");

        return $this->redirect('site7-studio/appearance');
    }

    /** Opens the site in a design through a signed link (works on read-only servers too). */
    public function actionPreview(): Response
    {
        $handle = DesignLoader::validHandle($this->request->getRequiredParam('design'));
        if ($handle === null) {
            return $this->redirect(Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '/');
        }

        return $this->redirect(DesignLoader::previewUrl($handle, 86400));
    }

    public function actionInstall(): Response
    {
        return $this->startJob('Install', ['site7-studio/design/install', $this->package()]);
    }

    public function actionUpdate(): Response
    {
        return $this->startJob('Update', ['site7-studio/library/update', $this->package()]);
    }

    public function actionVersion(): Response
    {
        $version = (string)$this->request->getRequiredBodyParam('version');
        if (!preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $version)) {
            $this->setFailFlash('Choose a version.');
            return $this->redirect('site7-studio/appearance');
        }

        return $this->startJob("Version {$version} of", ['site7-studio/design/version', $this->package(), $version]);
    }

    public function actionRemove(): Response
    {
        return $this->startJob('Remove', ['site7-studio/design/remove', $this->package()]);
    }

    private function package(): string
    {
        $package = (string)$this->request->getRequiredBodyParam('package');
        if (!preg_match('/^design-[a-z0-9][a-z0-9-]*$/', $package)) {
            throw new \yii\web\BadRequestHttpException('Not a design package.');
        }

        return $package;
    }

    private function startJob(string $verb, array $command): Response
    {
        $this->requirePostRequest();
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $this->setFailFlash('Designs are installed and changed on your development site, then deployed.');
            return $this->redirect('site7-studio/appearance');
        }
        $id = Site7Studio::getInstance()->siteKitJobs->start(
            "{$verb} {$command[1]}",
            $command,
            ['label' => 'Appearance', 'url' => 'site7-studio/appearance']
        );

        return $this->redirect("site7-studio/site-kits/job/{$id}");
    }
}
