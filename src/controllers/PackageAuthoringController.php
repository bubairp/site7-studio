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
     * Package Authoring's access rule: full access in Craft's Dev Mode; in
     * production, only through for a Template the current user personally
     * captured via "Save as Template" (mirrors
     * PackageActionController::actionDelete()'s own exception for deleting
     * a self-captured Template - here it also covers editing one). Pass
     * null when there's no existing package yet (actionNew/actionCreate) -
     * that always denies in production, since creating any brand new
     * package is Dev-Mode-only.
     */
    private function requireAuthoringAccess(?string $handle): void
    {
        if (Site7Studio::isDevMode()) {
            return;
        }

        $record = $handle !== null ? Site7Studio::getInstance()->packageManager->getPackageByHandle($handle) : null;
        $isOwnTemplate = $record
            && $record->type === 'template'
            && $record->creatorId !== null
            && (int)$record->creatorId === (int)Craft::$app->getUser()->getId();

        if (!$isOwnTemplate) {
            throw new \yii\web\ForbiddenHttpException('Package Authoring is only available in Dev Mode.');
        }
    }

    /**
     * The New Package wizard.
     */
    public function actionNew()
    {
        $this->requireAuthoringAccess(null);
        $this->view->registerAssetBundle(\site7\studio\assetbundles\LibraryBundle::class);

        $preselectedType = (string)Craft::$app->getRequest()->getQueryParam('type', 'section');
        if (!in_array($preselectedType, PackageAuthoringService::VALID_TYPES, true)) {
            $preselectedType = 'section';
        }

        return $this->renderTemplate('site7-studio/authoring/new', [
            'title' => 'New Package',
            'preselectedType' => $preselectedType,
        ]);
    }

    /**
     * Creates the package and redirects into its Editor.
     */
    public function actionCreate()
    {
        $this->requirePostRequest();
        $this->requireAuthoringAccess(null);

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
            return $this->redirect('site7-studio/packages/new?type=' . $meta['type']);
        }

        Craft::$app->getSession()->setNotice('Package created. Continue setting it up below.');
        return $this->redirect('site7-studio/packages/' . $record->handle . '/edit');
    }

    /**
     * The Package Editor.
     */
    public function actionEdit(string $handle)
    {
        $this->requireAuthoringAccess($handle);
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

        $availableTemplateItems = [];
        $templateComposition = [];
        if ($package->type === 'template') {
            $availableTemplateItems = $authoringService->getAvailableSectionsAndPatterns();
            $templateComposition = $authoringService->getTemplateComposition($handle);
        }

        $availableTemplates = [];
        $eligibleEntryTypes = [];
        $starterKitComposition = [];
        if ($package->type === 'starter-kit') {
            $availableTemplates = $authoringService->getAvailableTemplates();
            $eligibleEntryTypes = $authoringService->getEligibleEntryTypesForStarterKit();
            $starterKitComposition = $authoringService->getStarterKitComposition($handle);
        }

        $previewImageUrl = null;
        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($handle);
        if ($packagePath) {
            foreach (PackageAuthoringService::PREVIEW_IMAGE_EXTENSIONS as $extension) {
                $imagePath = $packagePath . '/preview/preview.' . $extension;
                if (file_exists($imagePath)) {
                    $previewImageUrl = \craft\helpers\UrlHelper::cpUrl('site7-studio/library/package/' . $handle . '/preview-image', ['v' => filemtime($imagePath)]);
                    break;
                }
            }
        }

        return $this->renderTemplate('site7-studio/authoring/edit', [
            'title' => 'Edit: ' . $package->name,
            'package' => $package,
            'sectionFields' => $sectionFields,
            'availableSections' => $availableSections,
            'patternComposition' => $patternComposition,
            'availableTemplateItems' => $availableTemplateItems,
            'templateComposition' => $templateComposition,
            'availableTemplates' => $availableTemplates,
            'eligibleEntryTypes' => $eligibleEntryTypes,
            'starterKitComposition' => $starterKitComposition,
            'previewImageUrl' => $previewImageUrl,
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
        $this->requireAuthoringAccess($handle);

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
     * Saves an uploaded preview thumbnail for the Package Editor.
     */
    public function actionUploadPreviewImage()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('handle');
        $this->requireAuthoringAccess($handle);
        $file = \craft\web\UploadedFile::getInstanceByName('previewImage');

        if (!$file) {
            Craft::$app->getSession()->setError('Choose an image to upload.');
            return $this->redirectToPostedUrl();
        }

        try {
            (new PackageAuthoringService())->savePreviewImage($handle, $file);
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Preview image updated.');
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
        $this->requireAuthoringAccess($handle);
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
        $this->requireAuthoringAccess($handle);
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

    /**
     * Saves the Template Builder's canvas.
     */
    public function actionSaveTemplate()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('handle');
        $this->requireAuthoringAccess($handle);
        $composition = json_decode((string)$request->getBodyParam('composition', '[]'), true);

        try {
            (new PackageAuthoringService())->saveTemplateComposition($handle, is_array($composition) ? $composition : []);
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Template saved.');
        return $this->redirectToPostedUrl();
    }

    /**
     * Saves the Starter Kit Builder's canvas.
     */
    public function actionSaveStarterKit()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('handle');
        $this->requireAuthoringAccess($handle);
        $composition = json_decode((string)$request->getBodyParam('composition', '[]'), true);

        try {
            (new PackageAuthoringService())->saveStarterKitComposition($handle, is_array($composition) ? $composition : []);
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Starter Kit saved.');
        return $this->redirectToPostedUrl();
    }
}
