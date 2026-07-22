<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;
use site7\studio\services\PackageAuthoringService;

/**
 * Backs the Package Authoring Platform: the New Package wizard and the
 * Package Editor (General + Package Information tabs, this milestone's
 * scope). One workflow for all four package types, per Phase 11.
 */
class PackageAuthoringController extends Controller
{
    /**
     * The New Package wizard.
     */
    public function actionNew()
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\LibraryBundle::class);

        return $this->renderTemplate('site7-studio/authoring/new', [
            'title' => 'New Package',
        ]);
    }

    /**
     * Creates the package and redirects into its Editor.
     */
    public function actionCreate()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $meta = [
            'type' => (string)$request->getRequiredBodyParam('type'),
            'name' => (string)$request->getRequiredBodyParam('name'),
            'handle' => (string)$request->getBodyParam('handle', ''),
            'description' => (string)$request->getBodyParam('description', ''),
            'category' => (string)$request->getBodyParam('category', ''),
            'tags' => (string)$request->getBodyParam('tags', ''),
            'version' => (string)$request->getBodyParam('version', '1.0.0'),
            'author' => (string)$request->getBodyParam('author', ''),
        ];

        try {
            $record = (new PackageAuthoringService())->createPackage($meta);
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Package created. Continue setting it up below.');
        return $this->redirect('site7-studio/packages/' . $record->handle . '/edit');
    }

    /**
     * The Package Editor.
     */
    public function actionEdit(string $handle)
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\LibraryBundle::class);

        $package = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        if (!$package) {
            throw new \yii\web\NotFoundHttpException('Package not found');
        }

        $authoringService = new PackageAuthoringService();

        $sectionFields = $package->type === 'section'
            ? $authoringService->getSectionFields($handle)
            : [];

        $availableSections = [];
        $patternComposition = [];
        if ($package->type === 'pattern') {
            $availableSections = $authoringService->getAvailableSections();
            $patternComposition = $authoringService->getPatternComposition($handle);
        }

        return $this->renderTemplate('site7-studio/authoring/edit', [
            'title' => 'Edit: ' . $package->name,
            'package' => $package,
            'sectionFields' => $sectionFields,
            'availableSections' => $availableSections,
            'patternComposition' => $patternComposition,
        ]);
    }

    /**
     * Saves the Package Editor's General tab.
     */
    public function actionSave()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('handle');

        $fields = [];
        foreach (['name', 'description', 'category', 'author', 'version', 'tags'] as $key) {
            if ($request->getBodyParam($key) !== null) {
                $fields[$key] = (string)$request->getBodyParam($key);
            }
        }

        try {
            (new PackageAuthoringService())->updatePackage($handle, $fields);
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Package saved.');
        return $this->redirectToPostedUrl();
    }

    /**
     * Saves the Section Builder's field list.
     */
    public function actionSaveFields()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('handle');
        $fields = (array)$request->getBodyParam('fields', []);

        try {
            (new PackageAuthoringService())->saveSectionFields($handle, $fields);
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Package Builder saved.');
        return $this->redirectToPostedUrl();
    }

    /**
     * Saves the Pattern Builder's canvas.
     */
    public function actionSavePattern()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('handle');
        $composition = json_decode((string)$request->getBodyParam('composition', '[]'), true);

        try {
            (new PackageAuthoringService())->savePatternComposition($handle, is_array($composition) ? $composition : []);
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Pattern saved.');
        return $this->redirectToPostedUrl();
    }
}
