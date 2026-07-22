<?php

namespace site7\studio\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use craft\web\UploadedFile;
use site7\studio\Site7Studio;
use site7\studio\services\StarterKitGeneratorService;
use site7\studio\services\StarterKitInstallationService;

class StarterKitGeneratorController extends Controller
{
    /**
     * Lists the Entries eligible to be captured into a Starter Kit - anything
     * whose Entry Type's field layout includes the configured Site7 Matrix field,
     * mirroring TemplateInsertionService::getEligibleEntryTypes()'s eligibility rule.
     */
    public function actionGetEntries()
    {
        $this->requireAcceptsJson();
        if (!Site7Studio::isDevMode()) {
            throw new \yii\web\ForbiddenHttpException('Package Authoring is only available in Dev Mode.');
        }

        $settings = Site7Studio::getInstance()->getSettings();
        $matrixField = $settings->matrixFieldId ? Craft::$app->getFields()->getFieldById($settings->matrixFieldId) : null;
        if (!$matrixField) {
            return $this->asJson(['success' => true, 'entries' => []]);
        }

        $entriesService = Craft::$app->getEntries();
        $eligibleEntryTypeIds = [];
        foreach ($entriesService->getAllEntryTypes() as $entryType) {
            if ($entryType->getFieldLayout()?->getFieldByHandle($matrixField->handle)) {
                $eligibleEntryTypeIds[] = $entryType->id;
            }
        }

        if (empty($eligibleEntryTypeIds)) {
            return $this->asJson(['success' => true, 'entries' => []]);
        }

        $entries = Entry::find()->typeId($eligibleEntryTypeIds)->status(null)->orderBy('title')->all();

        $data = array_map(fn(Entry $e) => [
            'id' => $e->id,
            'title' => $e->title,
            'section' => $e->getSection()?->name,
        ], $entries);

        return $this->asJson(['success' => true, 'entries' => $data]);
    }

    /**
     * Generates a new Starter Kit package from the selected Entries ("Save Current
     * Site as Starter Kit").
     */
    public function actionSaveAsStarterKit()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        if (!Site7Studio::isDevMode()) {
            throw new \yii\web\ForbiddenHttpException('Package Authoring is only available in Dev Mode.');
        }

        $request = Craft::$app->getRequest();
        $entryIds = (array)$request->getRequiredBodyParam('entryIds');
        $name = trim((string)$request->getRequiredBodyParam('name'));

        if ($name === '') {
            return $this->asJson(['success' => false, 'error' => 'A Starter Kit name is required.']);
        }
        if (empty($entryIds)) {
            return $this->asJson(['success' => false, 'error' => 'Select at least one page.']);
        }

        $entries = Entry::find()->id($entryIds)->status(null)->all();
        if (empty($entries)) {
            return $this->asJson(['success' => false, 'error' => 'No valid pages were selected.']);
        }

        $meta = [
            'name' => $name,
            'description' => (string)$request->getBodyParam('description', ''),
            'version' => (string)$request->getBodyParam('version', '1.0.0'),
            'author' => (string)$request->getBodyParam('author', ''),
            'category' => (string)$request->getBodyParam('category', ''),
            'tags' => (string)$request->getBodyParam('tags', ''),
            'previewImage' => UploadedFile::getInstanceByName('previewImage'),
        ];

        try {
            [$record, $skipped] = (new StarterKitGeneratorService())->generateFromEntries($entries, $meta);
        } catch (\Throwable $e) {
            Craft::error('Save as Starter Kit failed: ' . $e->getMessage(), __METHOD__);
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'handle' => $record->handle, 'skipped' => $skipped]);
    }

    /**
     * Installs a Starter Kit's captured pages into the current project ("Install
     * Starter Kit").
     */
    public function actionInstall()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');

        try {
            $summary = (new StarterKitInstallationService())->installStarterKit($handle);
        } catch (\Throwable $e) {
            Craft::error('Install Starter Kit failed: ' . $e->getMessage(), __METHOD__);
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson([
            'success' => true,
            'createdCount' => count($summary['createdEntries']),
            'skipped' => $summary['skipped'],
            'installedTemplates' => $summary['installedTemplates'],
        ]);
    }
}
