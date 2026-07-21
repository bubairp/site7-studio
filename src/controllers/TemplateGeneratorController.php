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

        $entry = Craft::$app->getEntries()->getEntryById((int)$entryId);
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
}
