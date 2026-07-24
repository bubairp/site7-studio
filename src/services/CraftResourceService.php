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
     * Deliberately small, bidirectional map between the 'type' string used in
     * fields.yaml and the Craft field class it represents - not an attempt at
     * full Craft field-type coverage. Anything not listed here is unsupported:
     * createCraftField() falls back to PlainText for it (existing behavior,
     * preserved for packages whose fields.yaml never set a type), and
     * describeField()/the Resource Importer's validator flag it as skipped.
     *
     * Assets is intentionally detect-only - the write direction (creating an
     * Assets field from an imported package) is out of scope; Assets fields
     * encountered on import are always reported and skipped, never migrated.
     *
     * Ckeditor is a soft dependency (the craftcms/ckeditor plugin may not be
     * installed) - guarded with class_exists() everywhere it's used.
     */
    private const FIELD_TYPE_MAP = [
        'PlainText' => \craft\fields\PlainText::class,
        'Number' => \craft\fields\Number::class,
        'Lightswitch' => \craft\fields\Lightswitch::class,
        'Dropdown' => \craft\fields\Dropdown::class,
        'Date' => \craft\fields\Date::class,
        'Assets' => \craft\fields\Assets::class,
        'Ckeditor' => 'craft\\ckeditor\\Field',
        'Entries' => \craft\fields\Entries::class,
        'Matrix' => \craft\fields\Matrix::class,
    ];

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

        // Only a subset of FIELD_TYPE_MAP is writable - Assets is detect-only
        // (see the map's docblock), and Ckeditor requires its plugin to be
        // installed. Anything else, including an unrecognized type string,
        // falls back to PlainText - the pre-existing MVP behavior, preserved
        // for packages whose fields.yaml never set a type at all.
        $type = (string)($def['type'] ?? 'PlainText');
        $writableTypes = ['PlainText', 'Number', 'Lightswitch', 'Dropdown', 'Date', 'Entries', 'Matrix'];
        $fieldClass = PlainText::class;
        if (in_array($type, $writableTypes, true) && isset(self::FIELD_TYPE_MAP[$type])) {
            $fieldClass = self::FIELD_TYPE_MAP[$type];
        } elseif ($type === 'Ckeditor' && class_exists(self::FIELD_TYPE_MAP['Ckeditor'])) {
            $fieldClass = self::FIELD_TYPE_MAP['Ckeditor'];
        }

        $config = [
            'handle' => $def['handle'],
            'name' => $def['name'],
            'instructions' => $def['instructions'] ?? '',
        ];
        if ($fieldClass === \craft\fields\Dropdown::class) {
            $options = $def['options'] ?? [];
            $config['options'] = array_map(
                fn($o) => is_array($o) ? $o : ['label' => (string)$o, 'value' => (string)$o],
                !empty($options) ? $options : [['label' => 'Option 1', 'value' => 'option1']]
            );
        } elseif ($fieldClass === \craft\fields\Entries::class) {
            // Referenced Sections are resolved by handle (portable within the
            // same Craft install this was imported from) rather than the raw
            // UID captured by describeField() - a missing Section just
            // leaves the field unrestricted ('*') instead of failing the
            // whole install, same "never fatal, degrade gracefully"
            // philosophy as DependencyResolverService's Shared Resources.
            $sectionsService = Craft::$app->getEntries();
            $sources = [];
            foreach ((array)($def['settings']['sectionHandles'] ?? []) as $sectionHandle) {
                $section = $sectionsService->getSectionByHandle((string)$sectionHandle);
                if ($section) {
                    $sources[] = 'section:' . $section->uid;
                } else {
                    Craft::warning("Entries field '{$def['handle']}': Section '{$sectionHandle}' not found - left unrestricted.", __METHOD__);
                }
            }
            $config['sources'] = !empty($sources) ? $sources : '*';
            foreach (['maxRelations', 'minRelations', 'selectionLabel'] as $passthroughKey) {
                if (isset($def['settings'][$passthroughKey])) {
                    $config[$passthroughKey] = $def['settings'][$passthroughKey];
                }
            }
        } elseif ($fieldClass === \craft\fields\Matrix::class) {
            // Referenced Entry Types are resolved by handle, the same
            // already-exists-or-skip resolution used everywhere else in this
            // service (e.g. createMatrixEntryType() reusing an existing
            // handle match) - this only supports referencing Entry Types
            // that already exist in this Craft install, not recreating a
            // nested Entry Type's own field layout from scratch.
            $entriesService = Craft::$app->getEntries();
            $entryTypes = [];
            foreach ((array)($def['settings']['entryTypeHandles'] ?? []) as $entryTypeHandle) {
                $entryType = $entriesService->getEntryTypeByHandle((string)$entryTypeHandle);
                if ($entryType) {
                    $entryTypes[] = $entryType;
                } else {
                    Craft::warning("Matrix field '{$def['handle']}': Entry Type '{$entryTypeHandle}' not found - skipped.", __METHOD__);
                }
            }
            if (!empty($entryTypes)) {
                $config['entryTypes'] = $entryTypes;
            }
            if (!empty($def['settings']['viewMode'])) {
                $config['viewMode'] = $def['settings']['viewMode'];
            }
            if (isset($def['settings']['maxEntries'])) {
                $config['maxEntries'] = $def['settings']['maxEntries'];
            }
        }

        /** @var Field $field */
        $field = new $fieldClass($config);

        if ($fieldsService->saveField($field)) {
            return $field;
        }

        return null;
    }

    /**
     * The read-direction counterpart of createCraftField() - describes a live
     * Craft field back into the same {handle, name, type, instructions} shape
     * fields.yaml uses, so an imported field round-trips through this map.
     * Unmapped field classes are reported using their own short class name
     * (e.g. 'Matrix', 'Table') with $supported = false, so the Preview step
     * shows what the field actually is rather than the write side's PlainText
     * fallback - createCraftField() still falls back to PlainText if such a
     * package is ever installed elsewhere, but that's a write-time default,
     * not something this read-only description should misrepresent. The
     * caller (the Resource Analyzer/Validator) is responsible for surfacing
     * unsupported fields as a skip warning, not this method.
     *
     * @return array{handle: string, name: string, type: string, instructions: string, supported: bool}
     */
    public function describeField(Field $field): array
    {
        $class = get_class($field);
        $type = null;
        $supported = false;
        foreach (self::FIELD_TYPE_MAP as $mappedType => $mappedClass) {
            if ($class === $mappedClass || (class_exists($mappedClass) && $field instanceof $mappedClass)) {
                $type = $mappedType;
                $supported = true;
                break;
            }
        }
        if ($type === null) {
            $classParts = explode('\\', $class);
            $type = end($classParts);
        }

        return [
            'handle' => $field->handle,
            'name' => $field->name,
            'type' => $type,
            'instructions' => (string)($field->instructions ?? ''),
            'supported' => $supported,
            // Portable (handle-based, never a raw UID/ID) settings needed to
            // recreate an Entries or Matrix field elsewhere in this same
            // Craft install - see createCraftField()'s counterpart branches.
            // Empty for every other field type.
            'settings' => $this->describeFieldSettings($field),
            // The field's own PHP class - additive metadata Phase 16's
            // ResourceClassifierService needs (e.g. to detect Categories/Tags
            // fields, or fields provided by a third-party plugin namespace).
            // Never used by createCraftField()'s write direction.
            'fieldClass' => $class,
            // The field's own UID - additive metadata so a Shared Resource
            // can be re-resolved via getFieldByUid() rather than
            // getFieldByHandle(), which defaults to the global field context
            // and silently misses fields whose real context is scoped
            // elsewhere (e.g. a field defined inline within a Matrix block
            // type's own field layout).
            'uid' => $field->uid,
        ];
    }

    /**
     * @return array Empty for anything but an Entries or Matrix field.
     */
    private function describeFieldSettings(Field $field): array
    {
        if ($field instanceof \craft\fields\Entries) {
            $sectionHandles = [];
            foreach ((array)($field->sources ?? []) as $source) {
                if (is_string($source) && str_starts_with($source, 'section:')) {
                    $section = Craft::$app->getEntries()->getSectionByUid(substr($source, 8));
                    if ($section) {
                        $sectionHandles[] = $section->handle;
                    }
                }
            }
            return [
                'sectionHandles' => $sectionHandles,
                'maxRelations' => $field->maxRelations,
                'minRelations' => $field->minRelations,
                'selectionLabel' => $field->selectionLabel,
            ];
        }

        if ($field instanceof \craft\fields\Matrix) {
            return [
                'entryTypeHandles' => array_map(fn($et) => $et->handle, $field->getEntryTypes()),
                'viewMode' => $field->viewMode ?? null,
                'maxEntries' => $field->maxEntries ?? null,
            ];
        }

        return [];
    }

    /**
     * Describes every custom field on a live Field Layout, in layout order,
     * via describeField() - the shared building block every Resource Importer
     * uses to turn a live Entry Type/Entry's field layout into fields.yaml
     * content (and, for scalar values, demo/entry field content). This is a
     * generalized, reusable version of what TemplateGeneratorService::
     * extractFieldValues() does privately and PlainText-only-safe; unlike that
     * method, this one records each field's real type alongside its value.
     *
     * @param FieldLayout $layout
     * @param string[] $skipHandles Fields to exclude (e.g. the Site7 Matrix field itself).
     * @return array<int, array{handle: string, name: string, type: string, instructions: string, supported: bool}>
     */
    public function describeFieldLayout(FieldLayout $layout, array $skipHandles = []): array
    {
        $fields = [];
        foreach ($layout->getCustomFields() as $field) {
            if (in_array($field->handle, $skipHandles, true)) {
                continue;
            }
            $fields[] = $this->describeField($field);
        }
        return $fields;
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
