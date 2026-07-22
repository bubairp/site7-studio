<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use site7\studio\Site7Studio;

class TemplateGeneratorController extends Controller
{
    /**
     * Generates a new Template package from an existing entry's current Site7 content
     * ("Save as Template").
     */
    public function actionSaveAsTemplate()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $entryId = $request->getRequiredBodyParam('entryId');
        $name = trim((string)$request->getRequiredBodyParam('name'));

        if ($name === '') {
            return $this->asJson(['success' => false, 'error' => 'A Template name is required.']);
        }

        $entry = Craft::$app->getEntries()->getEntryById((int)$entryId, Craft::$app->getSites()->getCurrentSite()->id);
        if (!$entry) {
            return $this->asJson(['success' => false, 'error' => 'Entry not found.']);
        }

        $meta = [
            'name' => $name,
            'description' => (string)$request->getBodyParam('description', ''),
            'category' => (string)$request->getBodyParam('category', ''),
            'tags' => (string)$request->getBodyParam('tags', ''),
            'previewImage' => UploadedFile::getInstanceByName('previewImage'),
        ];

        try {
            $record = (new \site7\studio\services\TemplateGeneratorService())->generateFromEntry($entry, $meta);
        } catch (\Throwable $e) {
            Craft::error('Save as Template failed: ' . $e->getMessage(), __METHOD__);
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'handle' => $record->handle]);
    }

    /**
     * Lists the Section/Entry Type options the "Create from Template" wizard can
     * generate an Entry into.
     */
    public function actionGetCreateOptions()
    {
        $this->requireAcceptsJson();

        $handle = Craft::$app->getRequest()->getParam('handle');
        $preferredEntryTypeHandle = null;
        if ($handle) {
            $package = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
            $preferredEntryTypeHandle = $package?->getManifest()?->sourceEntryType;
        }

        $entryTypes = (new \site7\studio\services\TemplateInsertionService())->getEligibleEntryTypes($preferredEntryTypeHandle);

        return $this->asJson(['success' => true, 'entryTypes' => $entryTypes]);
    }

    /**
     * Generates a brand new Entry from a Template package ("Create from Template").
     */
    public function actionCreateFromTemplate()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $handle = $request->getRequiredBodyParam('handle');
        $entryTypeId = (int)$request->getRequiredBodyParam('entryTypeId');
        $title = trim((string)$request->getRequiredBodyParam('title'));
        $slug = trim((string)$request->getBodyParam('slug', ''));

        if ($title === '') {
            return $this->asJson(['success' => false, 'error' => 'A Title is required.']);
        }

        try {
            $entry = (new \site7\studio\services\TemplateInsertionService())
                ->createEntryFromTemplate($handle, $entryTypeId, $title, $slug !== '' ? $slug : null);
        } catch (\Throwable $e) {
            Craft::error('Create from Template failed: ' . $e->getMessage(), __METHOD__);
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'cpEditUrl' => $entry->getCpEditUrl()]);
    }
}
