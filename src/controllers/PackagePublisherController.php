<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;

/**
 * Backs the Package Publishing Platform: the "Publishing" nav landing page
 * (aggregate publish history + registered repositories) and the per-package
 * Publish wizard (Validate -> Metadata -> Preview -> Repository -> Publish).
 *
 * Every action here only orchestrates - the actual business logic
 * (validation, building, publishing, version bumping, history) lives in the
 * publishing services, none of which this controller constructs directly -
 * all are reached through Site7Studio's service locator (registered by
 * PublishingServiceProvider), exactly like packageManager/marketplace already are.
 *
 * Access is gated by this phase's own granular permissions (publishPackages/
 * manageVersions/viewPublishHistory/managePackageMetadata) rather than the
 * Dev-Mode-only gate Package Authoring uses - the whole point of defining
 * those permissions is letting an admin grant publishing rights to a
 * specific user without handing them full Dev Mode/Package Authoring access.
 */
class PackagePublisherController extends Controller
{
    private const STEPS = ['validate', 'metadata', 'preview', 'repository'];

    /**
     * The Publishing nav landing page: aggregate publish history + registered repositories.
     */
    public function actionIndex()
    {
        $this->requirePermission('viewPublishHistory');
        $this->view->registerAssetBundle(\site7\studio\assetbundles\Site7StudioBundle::class);

        $plugin = Site7Studio::getInstance();

        return $this->renderTemplate('site7-studio/publishing/index', [
            'title' => 'Publishing',
            'history' => $plugin->publishHistory->getAllHistory(),
            'targets' => $plugin->repositoryManager->getTargets(),
        ]);
    }

    /**
     * The per-package Publish wizard.
     */
    public function actionWizard(string $handle)
    {
        $this->requirePermission('publishPackages');
        $this->view->registerAssetBundle(\site7\studio\assetbundles\Site7StudioBundle::class);

        $plugin = Site7Studio::getInstance();
        $record = $plugin->packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \yii\web\NotFoundHttpException('Package not found');
        }

        $step = (string)Craft::$app->getRequest()->getQueryParam('step', 'validate');
        if (!in_array($step, self::STEPS, true)) {
            $step = 'validate';
        }

        $data = [
            'title' => 'Publish: ' . $record->name,
            'package' => $record,
            'activeStep' => $step,
            'readiness' => $plugin->publishValidator->validatePackage($handle),
        ];

        if ($step === 'metadata') {
            $data['manifest'] = $record->getManifest();
        }

        if ($step === 'preview') {
            $data['history'] = $plugin->publishHistory->getHistory($handle);
        }

        if ($step === 'repository') {
            $data['targets'] = $plugin->repositoryManager->getPublishableTargets();
            $data['history'] = $plugin->publishHistory->getHistory($handle);
        }

        return $this->renderTemplate('site7-studio/publishing/wizard', $data);
    }

    /**
     * Saves the wizard's Metadata step, then continues to Preview.
     */
    public function actionSaveMetadata()
    {
        $this->requirePostRequest();
        $this->requirePermission('managePackageMetadata');

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('handle');

        $fields = [];
        foreach ([
            'displayName', 'description', 'category', 'tags', 'author', 'version',
            'company', 'website', 'supportUrl', 'documentationUrl',
            'license', 'pricingType', 'minimumCraftVersion', 'minimumSite7Version', 'keywords',
        ] as $key) {
            if ($request->getBodyParam($key) !== null) {
                $fields[$key] = (string)$request->getBodyParam($key);
            }
        }

        try {
            (new \site7\studio\services\PackageAuthoringService())->updatePackage($handle, $fields);
            Craft::$app->getSession()->setNotice('Metadata saved.');
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Could not save metadata: ' . $e->getMessage());
        }

        return $this->redirect('site7-studio/packages/' . $handle . '/publish?step=preview');
    }

    /**
     * Runs the full publish: validate -> build -> hand to the chosen repository -> record history.
     */
    public function actionPublish()
    {
        $this->requirePostRequest();
        $this->requirePermission('publishPackages');

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('handle');
        $repositoryHandle = (string)$request->getRequiredBodyParam('repositoryHandle');
        $releaseNotes = (string)$request->getBodyParam('releaseNotes', '');
        $bumpType = (string)$request->getBodyParam('bumpType', '');

        $options = ['repositoryHandle' => $repositoryHandle];
        if ($releaseNotes !== '') {
            $options['releaseNotes'] = $releaseNotes;
        }
        if (in_array($bumpType, ['patch', 'minor', 'major'], true)) {
            $options['bumpType'] = $bumpType;
        }

        $result = Site7Studio::getInstance()->publisher->publish($handle, $options);

        if ($result->success) {
            Craft::$app->getSession()->setNotice($result->message);
            return $this->redirect('site7-studio/library/package/' . $handle);
        }

        Craft::$app->getSession()->setError($result->message);
        return $this->redirect('site7-studio/packages/' . $handle . '/publish?step=repository');
    }

    /**
     * Standalone "Create New Version" action, reachable from Package Details
     * independently of a full publish (see VersionManagerService).
     */
    public function actionCreateVersion()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageVersions');

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('handle');
        $bumpType = (string)$request->getRequiredBodyParam('bumpType');
        $releaseNotes = (string)$request->getBodyParam('releaseNotes', '');

        try {
            $version = Site7Studio::getInstance()->versionManager->createVersion($handle, $bumpType, $releaseNotes !== '' ? $releaseNotes : null);
            Craft::$app->getSession()->setNotice("Version {$version->version} created.");
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Could not create version: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl();
    }
}
