<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\base\Field;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\FieldLayoutElement;
use craft\fieldlayoutelements\CustomField;
use Symfony\Component\Yaml\Yaml;

class CraftResourceService extends Component
{
    /**
     * Generates Craft resources (Fields, Entry Types, Field Layouts) from the package manifests.
     * 
     * @param string $packagePath The absolute path to the package directory.
     * @return array Array of created resource UIDs for rollback capability.
     */
    public function generateResources(string $packagePath): array
    {
        $createdResources = [
            'fields' => [],
            'entryTypes' => []
        ];

        // 1. Parse fields.yaml
        $fieldsYamlPath = $packagePath . '/fields.yaml';
        if (file_exists($fieldsYamlPath)) {
            $fieldsData = Yaml::parseFile($fieldsYamlPath);
            if (isset($fieldsData['fields']) && is_array($fieldsData['fields'])) {
                foreach ($fieldsData['fields'] as $fieldDef) {
                    $field = $this->createCraftField($fieldDef);
                    if ($field) {
                        $createdResources['fields'][] = $field->uid;
                    }
                }
            }
        }

        // 2. Parse matrix.yaml
        $matrixYamlPath = $packagePath . '/matrix.yaml';
        if (file_exists($matrixYamlPath)) {
            $matrixData = Yaml::parseFile($matrixYamlPath);
            if (isset($matrixData['blocks']) && is_array($matrixData['blocks'])) {
                foreach ($matrixData['blocks'] as $blockDef) {
                    $entryType = $this->createMatrixEntryType($blockDef);
                    if ($entryType) {
                        $createdResources['entryTypes'][] = $entryType->uid;
                    }
                }
            }
        }

        // 3. Register Templates (Copy template.twig to @templates/site7-components/...)
        $templatePath = $packagePath . '/template.twig';
        if (file_exists($templatePath)) {
            $destDir = Craft::getAlias('@templates/site7-components');
            if (!is_dir($destDir)) {
                \yii\helpers\FileHelper::createDirectory($destDir);
            }
            // For MVP, assume it maps to the first block handle
            if (isset($matrixData['blocks'][0]['handle'])) {
                $blockHandle = $matrixData['blocks'][0]['handle'];
                copy($templatePath, $destDir . '/' . $blockHandle . '.twig');
            }
        }

        return $createdResources;
    }

    /**
     * @param array $def
     * @return Field|null
     */
    private function createCraftField(array $def): ?Field
    {
        $fieldsService = Craft::$app->getFields();
        
        // Check if field already exists to prevent duplication
        if ($fieldsService->getFieldByHandle($def['handle'])) {
            return $fieldsService->getFieldByHandle($def['handle']);
        }

        // For MVP, we only map PlainText, but we could map more based on $def['type']
        $field = new PlainText([
            'handle' => $def['handle'],
            'name' => $def['name'],
            'instructions' => $def['instructions'] ?? '',
        ]);

        if ($fieldsService->saveField($field)) {
            return $field;
        }

        return null;
    }

    /**
     * @param array $def
     * @return EntryType|null
     */
    private function createMatrixEntryType(array $def): ?EntryType
    {
        $entriesService = Craft::$app->getEntries();
        
        // Check if exists
        $existing = $entriesService->getEntryTypeByHandle($def['handle']);
        if ($existing) {
            return $existing;
        }

        $entryType = new EntryType([
            'handle' => $def['handle'],
            'name' => $def['name'],
            'hasTitleField' => false,
        ]);

        // Create Field Layout
        $layout = new FieldLayout();
        $tab = new FieldLayoutTab(['name' => 'Content', 'sortOrder' => 1]);
        $elements = [];

        if (isset($def['fields']) && is_array($def['fields'])) {
            $fieldsService = Craft::$app->getFields();
            foreach ($def['fields'] as $fieldHandle) {
                $field = $fieldsService->getFieldByHandle($fieldHandle);
                if ($field) {
                    $elements[] = new CustomField($field);
                }
            }
        }
        $layout->setTabs([$tab]);
        $tab->setLayout($layout);
        $tab->setElements($elements);
        $entryType->setFieldLayout($layout);

        if ($entriesService->saveEntryType($entryType)) {
            return $entryType;
        }

        return null;
    }


    public function removeResources(array $resources): void
    {
        // 1. Remove Entry Types
        if (isset($resources['entryTypes'])) {
            $entriesService = Craft::$app->getEntries();
            foreach ($resources['entryTypes'] as $uid) {
                $entryType = $entriesService->getEntryTypeByUid($uid);
                if ($entryType) {
                    $entriesService->deleteEntryType($entryType);
                }
            }
        }

        // 2. Remove Fields
        if (isset($resources['fields'])) {
            $fieldsService = Craft::$app->getFields();
            foreach ($resources['fields'] as $uid) {
                $field = $fieldsService->getFieldByUid($uid);
                if ($field) {
                    $fieldsService->deleteField($field);
                }
            }
        }
    }

    /**
     * Parses the package yaml files and deletes the corresponding Craft resources.
     */
    public function removePackageResources(string $packagePath): void
    {
        // 1. Remove Entry Types from matrix.yaml
        $matrixYamlPath = $packagePath . '/matrix.yaml';
        if (file_exists($matrixYamlPath)) {
            $matrixData = \Symfony\Component\Yaml\Yaml::parseFile($matrixYamlPath);
            if (isset($matrixData['blocks']) && is_array($matrixData['blocks'])) {
                $entriesService = Craft::$app->getEntries();
                foreach ($matrixData['blocks'] as $blockDef) {
                    $entryType = $entriesService->getEntryTypeByHandle($blockDef['handle']);
                    if ($entryType) {
                        $entriesService->deleteEntryType($entryType);
                    }
                }
            }
        }

        // 2. Remove Fields from fields.yaml
        $fieldsYamlPath = $packagePath . '/fields.yaml';
        if (file_exists($fieldsYamlPath)) {
            $fieldsData = \Symfony\Component\Yaml\Yaml::parseFile($fieldsYamlPath);
            if (isset($fieldsData['fields']) && is_array($fieldsData['fields'])) {
                $fieldsService = Craft::$app->getFields();
                foreach ($fieldsData['fields'] as $fieldDef) {
                    $field = $fieldsService->getFieldByHandle($fieldDef['handle']);
                    if ($field) {
                        $fieldsService->deleteField($field);
                    }
                }
            }
        }

        // 3. Remove Template from @templates/site7-components
        if (file_exists($matrixYamlPath)) {
            if (isset($matrixData['blocks'][0]['handle'])) {
                $blockHandle = $matrixData['blocks'][0]['handle'];
                $destFile = Craft::getAlias('@templates/site7-components') . '/' . $blockHandle . '.twig';
                if (file_exists($destFile)) {
                    unlink($destFile);
                }
            }
        }
    }
}
