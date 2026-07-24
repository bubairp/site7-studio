<?php

namespace site7\studio\services\import;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\FileHelper;
use site7\studio\events\ResourceImportedEvent;
use site7\studio\records\PackageRecord;
use site7\studio\services\TemplateGeneratorService;
use site7\studio\Site7Studio;
use Symfony\Component\Yaml\Yaml;

/**
 * Converts an existing Entry ("page") into a Template package - the "Import
 * Existing Page" flow. Existing Site7 tooling (TemplateGeneratorService,
 * "Save as Template") already handles pages that have Site7 Matrix content;
 * this service's genuinely new behavior is pages that DON'T - i.e. plain
 * Craft pages authored before Site7 Studio was ever installed, or ones that
 * simply never used the Matrix field. For those, the page's own native field
 * layout is captured into entryFields with an empty demoContent/requires,
 * which is the correct, honest representation of "a page with no Site7
 * content" rather than failing outright.
 */
class PageImportService extends Component
{
    /**
     * @param array $meta {name?, description?, category?, tags?, version?}
     * @throws \Exception if the entry has no Site7 content AND no capturable native fields.
     */
    public function importFromEntry(Entry $entry, array $meta): PackageRecord
    {
        $matrixHandle = $this->getMatrixFieldHandle();

        if ($matrixHandle && $entry->getFieldLayout()?->getFieldByHandle($matrixHandle)) {
            $fieldValue = $entry->getFieldValue($matrixHandle);
            $blockCount = $fieldValue ? $fieldValue->status(null)->drafts(null)->savedDraftsOnly(false)->count() : 0;
            if ($blockCount > 0) {
                // Already has Site7 content - delegate to the existing, unmodified
                // "Save as Template" path rather than duplicating its logic.
                $meta['name'] = $meta['name'] ?? $entry->title;
                return (new TemplateGeneratorService())->generateFromEntry($entry, $meta);
            }
        }

        return $this->importNativeContent($entry, $meta, $matrixHandle);
    }

    /**
     * Captures a page's native (non-Site7) field layout into a Template
     * package whose demoContent/requires stay empty - there is no Site7
     * Section content to reference, only the page's own custom field values.
     */
    private function importNativeContent(Entry $entry, array $meta, ?string $matrixHandle): PackageRecord
    {
        $craftResourceService = Site7Studio::getInstance()->craftResourceGenerator;
        $layout = $entry->getFieldLayout();
        $skipHandles = $matrixHandle ? [$matrixHandle] : [];
        $describedFields = $layout ? $craftResourceService->describeFieldLayout($layout, $skipHandles) : [];
        $detectedFields = (new ResourceClassifierService())->classifyFieldLayout($describedFields);

        $liveFieldsByHandle = [];
        foreach ($layout?->getCustomFields() ?? [] as $liveField) {
            $liveFieldsByHandle[$liveField->handle] = $liveField;
        }

        // Only Feature Resource fields get their value captured - Shared/
        // Package/Platform/Plugin/Unknown resources are referenced or
        // reported, never stringified here. Values still aren't blindly cast:
        // arbitrary third-party field value objects (e.g. SEO plugin data
        // models) aren't guaranteed to implement __toString() and would fatal
        // on a raw (string) cast.
        $entryFields = [];
        $sharedResourceHandles = [];
        $pluginDependencies = [];
        $excludedFields = [];
        $registry = Site7Studio::getInstance()->sharedResourceRegistry;
        foreach ($detectedFields as $field) {
            if ($field['classification'] === ResourceClassifierService::SHARED_RESOURCE) {
                if (isset($liveFieldsByHandle[$field['handle']])) {
                    $registry->registerField($liveFieldsByHandle[$field['handle']], $field);
                }
                $sharedResourceHandles[] = $field['handle'];
                continue;
            }
            if (in_array($field['classification'], [ResourceClassifierService::PLUGIN_DEPENDENCY, ResourceClassifierService::EXTERNAL_DEPENDENCY], true)) {
                $pluginDependencies[] = ['handle' => $field['handle'], 'requiredPlugin' => $field['requiredPlugin'] ?? 'unknown'];
                continue;
            }
            // Entries/Matrix fields now classify as capturable (Feature
            // Dependency/Nested Resource/Reusable Component - Craft can
            // recreate the field itself, see CraftResourceService), but a
            // page's own field VALUE for either is a relation query/nested
            // entries, not something (string) can serialize - captured here
            // as an entry field would silently coerce to gibberish. This
            // import path only ever captures scalar page content, so both
            // still fall through to excludedFields rather than a broken
            // stringified value.
            $isUnserializableValueType = in_array($field['type'] ?? '', ['Entries', 'Matrix'], true);
            if (!ResourceClassifierService::isCapturable($field['classification']) || $isUnserializableValueType) {
                // Platform Configuration and Review Required fields - not
                // captured as page content and not a dependency either, but
                // recorded so they're still visible on the Package Editor
                // rather than silently disappearing after import.
                $excludedFields[] = [
                    'handle' => $field['handle'],
                    'name' => $field['name'],
                    'type' => $field['type'],
                    'classification' => $field['classification'],
                    'statusLabel' => $isUnserializableValueType ? 'Not Captured' : ($field['statusLabel'] ?? $field['classification']),
                    'detail' => $isUnserializableValueType
                        ? "This page's own {$field['name']} value can't be captured as static demo content - only the Section field itself is portable, not a page's specific selection."
                        : ($field['detail'] ?? ''),
                ];
                continue;
            }
            $value = $entry->getFieldValue($field['handle']);
            if (is_scalar($value) || $value === null) {
                $entryFields[$field['handle']] = $value;
            } elseif (is_object($value) && method_exists($value, '__toString')) {
                $entryFields[$field['handle']] = (string)$value;
            }
        }

        $hasCapturableContent = !empty($entryFields);

        $name = trim((string)($meta['name'] ?? $entry->title));
        if ($name === '') {
            throw new \Exception('A Template name is required.');
        }
        $version = (string)($meta['version'] ?? '1.0.0');

        $validator = new ResourceImportValidator();
        $proposedHandle = $validator->generateUniqueHandle($name);
        $validation = $validator->validateImport('page', [
            'detectedFields' => $detectedFields,
            'hasCapturableContent' => $hasCapturableContent,
            'proposedHandle' => $proposedHandle,
            'version' => $version,
        ]);
        if (!empty($validation['errors'])) {
            throw new \Exception(implode(' ', $validation['errors']));
        }

        $handle = $proposedHandle;
        $packagePath = rtrim(Craft::getAlias('@packages'), '/') . '/' . $handle;
        FileHelper::createDirectory($packagePath);

        $tags = array_values(array_filter(array_map('trim', explode(',', (string)($meta['tags'] ?? '')))));

        $manifest = [
            'schemaVersion' => '1',
            'handle' => $handle,
            'name' => $name,
            'type' => 'template',
            'version' => $version,
            'author' => !empty($meta['author']) ? $meta['author'] : (Craft::$app->getUser()->getIdentity()?->friendlyName ?? 'Site7'),
            'description' => !empty($meta['description']) ? $meta['description'] : 'Imported from an existing page with no Site7 content.',
            'category' => $meta['category'] ?: null,
            'tags' => $tags,
            'sourceEntryType' => $entry->getType()->handle,
            'sourceSection' => $entry->getSection()?->handle,
            'requires' => [],
            'demoContent' => [],
            'entryFields' => $entryFields,
            'dependencies' => [
                'sharedResources' => array_values(array_unique($sharedResourceHandles)),
                'pluginDependencies' => $pluginDependencies,
            ],
            'excludedFields' => $excludedFields,
            'importedFrom' => [
                'sourceType' => 'entry',
                'sourceId' => $entry->id,
                'sourceHandle' => $entry->getType()->handle,
                'importedAt' => date('c'),
                'importedBy' => Craft::$app->getUser()->getIdentity()?->friendlyName ?? null,
            ],
        ];

        file_put_contents($packagePath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($packagePath . '/README.md', "# {$name}\n\nImported from the page \"{$entry->title}\" - a page with no Site7 Section content, capturing its own native fields only.\n");

        FileHelper::createDirectory($packagePath . '/preview');
        file_put_contents($packagePath . '/preview/preview-data.yaml', Yaml::dump(['block' => []], 4));
        file_put_contents($packagePath . '/preview/preview.twig', "<div class=\"site7-template-preview\">\n    <p class=\"light\">This Template has no Site7 Section content - only native page fields.</p>\n</div>\n");

        $packageManager = Site7Studio::getInstance()->packageManager;
        $packageManager->discoverPackages();
        $packageManager->installPackage($handle);
        $packageManager->enablePackage($handle);

        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception('Template was imported but could not be registered.');
        }
        $record->creatorId = Craft::$app->getUser()->getId();
        $record->save();

        Site7Studio::getInstance()->marketplace->syncDependencyRecords($record);

        Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new ResourceImportedEvent([
            'sourceType' => 'entry',
            'sourceId' => $entry->id,
            'packageHandles' => [$handle],
            'summary' => ['fieldCount' => count($entryFields)],
        ]));

        return $record;
    }

    private function getMatrixFieldHandle(): ?string
    {
        $settings = Site7Studio::getInstance()->getSettings();
        if (!$settings->matrixFieldId) {
            return null;
        }
        $field = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
        return $field?->handle;
    }
}
