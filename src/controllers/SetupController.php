<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;
use craft\fields\Matrix;

class SetupController extends Controller
{
    public function actionIndex()
    {
        $settings = Site7Studio::getInstance()->getSettings();
        $isComplete = !empty($settings->matrixFieldId);
        
        $fieldsService = Craft::$app->getFields();
        $allFields = $fieldsService->getAllFields();
        $matrixFields = array_filter($allFields, function($field) {
            return $field instanceof Matrix;
        });

        return $this->renderTemplate('site7-studio/setup/index', [
            'isComplete' => $isComplete,
            'matrixFields' => $matrixFields,
        ]);
    }

    public function actionSave()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $matrixOption = $request->getBodyParam('matrixOption');
        $fieldId = null;

        $fieldsService = Craft::$app->getFields();

        if ($matrixOption === 'create') {
            $existing = $fieldsService->getFieldByHandle('site7Components');
            if ($existing) {
                $fieldId = $existing->id;
            } else {
                $matrixField = new Matrix([
                    'handle' => 'site7Components',
                    'name' => 'Site7 Components',
                ]);
                
                // Craft 5 removed field groups, so we can just save the field
                if ($fieldsService->saveField($matrixField)) {
                    $fieldId = $matrixField->id;
                } else {
                    Craft::$app->getSession()->setError('Failed to create Matrix field.');
                    return null;
                }
            }
        } elseif ($matrixOption === 'select') {
            $fieldId = $request->getBodyParam('matrixFieldId');
            if (!$fieldId) {
                Craft::$app->getSession()->setError('Please select a Matrix field.');
                return null;
            }
        }

        if ($fieldId) {
            Craft::$app->getPlugins()->savePluginSettings(
                Site7Studio::getInstance(),
                ['matrixFieldId' => $fieldId]
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
