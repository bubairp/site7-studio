<?php

namespace site7\studio\services\import;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\fields\Categories;
use craft\fields\Tags;
use site7\studio\models\import\ResourceImportAnalysis;
use site7\studio\Site7Studio;

/**
 * The Resource Importer's "Analyze" step - a pure read/compute service, no
 * disk writes. Inspects a live Craft resource (Entry Type, Section, Entry,
 * or a website selection of Entries + Global Sets), detects its fields,
 * entry types, and dependencies, and builds a preview of the manifest.json
 * the corresponding import*() service would write - all before the user
 * commits via the wizard's Save step.
 *
 * Deliberately mirrors, rather than calls into, the write-side importers'
 * manifest-building logic - this service must never touch disk, so it can't
 * safely share code with methods whose whole job is to write to disk.
 */
class ResourceAnalyzerService extends Component
{
    public function analyzeMatrixEntryType(int $entryTypeId): ResourceImportAnalysis
    {
        $analysis = new ResourceImportAnalysis(['kind' => 'matrix-entry-type']);

        $entryType = Craft::$app->getEntries()->getEntryTypeById($entryTypeId);
        if (!$entryType) {
            $analysis->errors[] = 'Entry Type not found.';
            return $analysis;
        }

        $analysis->sourceLabel = "Entry Type: {$entryType->name}";

        $craftResourceService = Site7Studio::getInstance()->craftResourceGenerator;
        $layout = $entryType->getFieldLayout();
        $describedFields = $layout ? $craftResourceService->describeFieldLayout($layout) : [];
        $detectedFields = (new ResourceClassifierService())->classifyFieldLayout($describedFields);
        $analysis->detectedFields = $detectedFields;

        $importableFields = array_values(array_filter($detectedFields, fn($f) => $f['classification'] === ResourceClassifierService::FEATURE_RESOURCE));
        $hasCapturableContent = !empty($importableFields);

        $validator = new ResourceImportValidator();
        $proposedHandle = $validator->generateUniqueHandle($entryType->name);
        $analysis->proposedHandle = $proposedHandle;

        $analysis->proposedManifest = [
            'schemaVersion' => '1',
            'handle' => $proposedHandle,
            'name' => $entryType->name,
            'type' => 'section',
            'version' => '1.0.0',
            'requires' => [],
            'importedFrom' => ['sourceType' => 'matrix-entry-type', 'sourceHandle' => $entryType->handle],
        ];

        $result = $validator->validateImport('matrix-entry-type', [
            'detectedFields' => $detectedFields,
            'hasCapturableContent' => $hasCapturableContent,
            'proposedHandle' => $proposedHandle,
        ]);
        $analysis->errors = $result['errors'];
        $analysis->warnings = $result['warnings'];
        $analysis->valid = empty($analysis->errors);
        $analysis->packageSizeEstimate = $this->estimateSize($analysis->proposedManifest, count($importableFields));

        return $analysis;
    }

    /**
     * @param int[] $entryTypeIds
     */
    public function analyzeCraftSection(int $sectionId, array $entryTypeIds): ResourceImportAnalysis
    {
        $analysis = new ResourceImportAnalysis(['kind' => 'craft-section']);

        $section = Craft::$app->getEntries()->getSectionById($sectionId);
        if (!$section) {
            $analysis->errors[] = 'Section not found.';
            return $analysis;
        }

        $analysis->sourceLabel = "Section: {$section->name} ({$section->type})";

        $craftResourceService = Site7Studio::getInstance()->craftResourceGenerator;
        $sectionEntryTypeIds = array_map(fn($et) => $et->id, $section->getEntryTypes());
        $selectedIds = array_values(array_intersect($entryTypeIds, $sectionEntryTypeIds));

        if (empty($selectedIds)) {
            $analysis->errors[] = 'Select at least one Entry Type from this Section.';
            return $analysis;
        }

        $classifier = new ResourceClassifierService();
        $allFields = [];
        $entryTypesOut = [];
        $hasCapturableContent = false;

        foreach ($selectedIds as $id) {
            $entryType = Craft::$app->getEntries()->getEntryTypeById($id);
            if (!$entryType) {
                continue;
            }
            $layout = $entryType->getFieldLayout();
            $describedFields = $layout ? $craftResourceService->describeFieldLayout($layout) : [];
            $fields = $classifier->classifyFieldLayout($describedFields);
            $entryTypesOut[] = ['id' => $entryType->id, 'handle' => $entryType->handle, 'name' => $entryType->name];
            $allFields = array_merge($allFields, $fields);
            if (!empty(array_filter($fields, fn($f) => $f['classification'] === ResourceClassifierService::FEATURE_RESOURCE))) {
                $hasCapturableContent = true;
            }
        }

        $analysis->detectedEntryTypes = $entryTypesOut;
        $analysis->detectedFields = $allFields;

        $validator = new ResourceImportValidator();
        $proposedHandle = $validator->generateUniqueHandle($section->name);
        $analysis->proposedHandle = $proposedHandle;
        $analysis->proposedManifest = [
            'note' => count($selectedIds) > 1
                ? 'One Section package will be generated per selected Entry Type.'
                : 'One Section package will be generated.',
            'entryTypes' => $entryTypesOut,
            'importedFrom' => ['sourceType' => 'craft-section', 'sourceHandle' => $section->handle],
        ];

        $result = $validator->validateImport('craft-section', [
            'detectedFields' => $allFields,
            'hasCapturableContent' => $hasCapturableContent,
            'proposedHandle' => $proposedHandle,
        ]);
        $analysis->errors = $result['errors'];
        $analysis->warnings = $result['warnings'];
        $analysis->valid = empty($analysis->errors);
        $analysis->packageSizeEstimate = $this->estimateSize($analysis->proposedManifest, count($allFields));

        return $analysis;
    }

    public function analyzeEntry(int $entryId): ResourceImportAnalysis
    {
        $analysis = new ResourceImportAnalysis(['kind' => 'page']);

        $entry = Entry::find()->id($entryId)->status(null)->one();
        if (!$entry instanceof Entry) {
            $analysis->errors[] = 'Page not found.';
            return $analysis;
        }

        $analysis->sourceLabel = "Page: {$entry->title}";

        $settings = Site7Studio::getInstance()->getSettings();
        $matrixHandle = null;
        if ($settings->matrixFieldId) {
            $matrixField = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
            $matrixHandle = $matrixField?->handle;
        }

        $hasSite7Content = false;
        if ($matrixHandle && $entry->getFieldLayout()?->getFieldByHandle($matrixHandle)) {
            $fieldValue = $entry->getFieldValue($matrixHandle);
            $hasSite7Content = $fieldValue && $fieldValue->status(null)->drafts(null)->savedDraftsOnly(false)->count() > 0;
        }

        $validator = new ResourceImportValidator();
        $proposedHandle = $validator->generateUniqueHandle($entry->title);
        $analysis->proposedHandle = $proposedHandle;

        if ($hasSite7Content) {
            $analysis->warnings[] = 'This page already has Site7 content - it will be captured via the existing "Save as Template" path.';
            $analysis->proposedManifest = [
                'handle' => $proposedHandle,
                'type' => 'template',
                'note' => 'Site7 Matrix content will be captured as demoContent/requires, same as "Save as Template".',
            ];
            $analysis->valid = true;
            $analysis->packageSizeEstimate = $this->estimateSize($analysis->proposedManifest, 1);
            return $analysis;
        }

        $craftResourceService = Site7Studio::getInstance()->craftResourceGenerator;
        $layout = $entry->getFieldLayout();
        $skipHandles = $matrixHandle ? [$matrixHandle] : [];
        $describedFields = $layout ? $craftResourceService->describeFieldLayout($layout, $skipHandles) : [];
        $detectedFields = (new ResourceClassifierService())->classifyFieldLayout($describedFields);
        $analysis->detectedFields = $detectedFields;

        $importableFields = array_values(array_filter($detectedFields, fn($f) => $f['classification'] === ResourceClassifierService::FEATURE_RESOURCE));
        $hasCapturableContent = !empty($importableFields);

        $analysis->proposedManifest = [
            'handle' => $proposedHandle,
            'name' => $entry->title,
            'type' => 'template',
            'sourceEntryType' => $entry->getType()->handle,
            'sourceSection' => $entry->getSection()?->handle,
            'entryFields' => array_map(fn($f) => $f['handle'], $importableFields),
            'demoContent' => [],
            'requires' => [],
        ];

        $result = $validator->validateImport('page', [
            'detectedFields' => $detectedFields,
            'hasCapturableContent' => $hasCapturableContent,
            'proposedHandle' => $proposedHandle,
        ]);
        $analysis->errors = $result['errors'];
        $analysis->warnings = array_merge($analysis->warnings, $result['warnings']);
        $analysis->valid = empty($analysis->errors);
        $analysis->packageSizeEstimate = $this->estimateSize($analysis->proposedManifest, count($importableFields));

        return $analysis;
    }

    /**
     * @param int[] $entryIds
     * @param int[] $globalSetIds
     */
    public function analyzeWebsite(array $entryIds, array $globalSetIds): ResourceImportAnalysis
    {
        $analysis = new ResourceImportAnalysis(['kind' => 'website']);

        $entries = Entry::find()->id($entryIds)->status(null)->all();
        if (empty($entries)) {
            $analysis->errors[] = 'Select at least one page.';
            return $analysis;
        }

        $analysis->sourceLabel = count($entries) . ' page(s), ' . count($globalSetIds) . ' Global Set(s)';

        $entryTypesSeen = [];
        $dependencies = [];
        foreach ($entries as $entry) {
            /** @var Entry $entry */
            $type = $entry->getType();
            $entryTypesSeen[$type->id] = ['id' => $type->id, 'handle' => $type->handle, 'name' => $type->name];

            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if ($field instanceof Categories) {
                    $dependencies[] = ['kind' => 'Category Group', 'handle' => $field->handle, 'status' => 'not-packaged'];
                } elseif ($field instanceof Tags) {
                    $dependencies[] = ['kind' => 'Tag Group', 'handle' => $field->handle, 'status' => 'not-packaged'];
                }
            }
        }

        $analysis->detectedEntryTypes = array_values($entryTypesSeen);
        $analysis->detectedDependencies = $dependencies;

        $globalSetsOut = [];
        foreach ($globalSetIds as $globalSetId) {
            $globalSet = Craft::$app->getGlobals()->getSetById((int)$globalSetId);
            if ($globalSet instanceof GlobalSet) {
                $globalSetsOut[] = ['id' => $globalSet->id, 'handle' => $globalSet->handle, 'name' => $globalSet->name];
            }
        }

        $validator = new ResourceImportValidator();
        $proposedHandle = $validator->generateUniqueHandle('Website Import');
        $analysis->proposedHandle = $proposedHandle;
        $analysis->proposedManifest = [
            'type' => 'starter-kit',
            'pageCount' => count($entries),
            'globals' => $globalSetsOut,
        ];

        $result = $validator->validateImport('website', [
            'hasCapturableContent' => true,
            'dependencies' => $dependencies,
            'proposedHandle' => $proposedHandle,
        ]);
        $analysis->errors = $result['errors'];
        $analysis->warnings = $result['warnings'];
        $analysis->valid = empty($analysis->errors);
        $analysis->packageSizeEstimate = $this->estimateSize($analysis->proposedManifest, count($entries) * 5);

        return $analysis;
    }

    private function estimateSize(array $manifestPreview, int $fieldCount): int
    {
        // manifest.json + README.md + a rough per-field allowance for
        // fields.yaml/matrix.yaml/template.twig/preview-data.yaml content.
        return strlen(json_encode($manifestPreview)) + 512 + ($fieldCount * 96);
    }
}
