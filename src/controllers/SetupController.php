<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;
use site7\studio\models\Settings;
use craft\fields\Matrix;

class SetupController extends Controller
{
    public function actionIndex()
    {
        $settings = Site7Studio::getInstance()->getSettings();
        $isComplete = !empty($settings->matrixFieldId);

        return $this->renderTemplate('site7-studio/setup/index', [
            'isComplete' => $isComplete,
            'matrixFields' => array_filter(Craft::$app->getFields()->getAllFields(), fn($field) => $field instanceof Matrix),
            'currentFieldId' => $settings->matrixFieldId,
        ]);
    }

    /**
     * Option A: the site7Components field, created if needed. Option B: the
     * site's own page-builder Matrix field (e.g. rp-craft's matrixContent),
     * so its existing block types can be imported into the Library and
     * Section packages install into the field its pages already render.
     */
    public function actionSave()
    {
        $this->requirePostRequest();

        $fieldId = null;
        $fieldsService = Craft::$app->getFields();

        if ($this->request->getBodyParam('matrixOption') === 'select') {
            $selected = $fieldsService->getFieldById((int)$this->request->getBodyParam('matrixFieldId'));
            if (!$selected instanceof Matrix) {
                Craft::$app->getSession()->setError('Choose a Matrix field.');
                return null;
            }
            $fieldId = $selected->id;
        } elseif ($existing = $fieldsService->getFieldByHandle('site7Components')) {
            $fieldId = $existing->id;
        } else {
            $matrixField = new Matrix([
                'handle' => 'site7Components',
                'name' => 'Site7 Components',
                'viewMode' => 'blocks',
            ]);

            // Craft 5 removed field groups, so we can just save the field.
            // A brand new Matrix field legitimately has zero Entry Types
            // at this point - they arrive later as Section packages are
            // enabled - but Craft 5's default validation rejects a Matrix
            // field with none, so skip validation for this one
            // intentionally-transient save.
            if ($fieldsService->saveField($matrixField, false)) {
                $fieldId = $matrixField->id;
            } else {
                Craft::$app->getSession()->setError('Failed to create Matrix field.');
                return null;
            }
        }

        if ($fieldId) {
            Craft::$app->getPlugins()->savePluginSettings(
                Site7Studio::getInstance(),
                Settings::mergeWithStored(['matrixFieldUid' => $fieldsService->getFieldById($fieldId)?->uid])
            );
            Craft::$app->getSession()->setNotice('Setup complete!');
            return $this->redirect('site7-studio/setup/complete');
        }

        Craft::$app->getSession()->setError('Setup failed.');
        return null;
    }

    public function actionComplete()
    {
        return $this->renderTemplate('site7-studio/setup/complete');
    }
}
