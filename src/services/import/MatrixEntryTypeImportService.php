<?php

namespace site7\studio\services\import;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\models\EntryType;
use site7\studio\events\ResourceImportedEvent;
use site7\studio\records\PackageRecord;
use site7\studio\Site7Studio;
use Symfony\Component\Yaml\Yaml;

/**
 * Converts an existing Craft Entry Type (whether or not it's currently used
 * as a block in the Site7 Matrix field) into a Section package - the "Import
 * Existing Section" flow's Matrix Entry Type source. Writes exactly the
 * three files CraftResourceService reads on install (fields.yaml,
 * matrix.yaml, template.twig), the same as PackageAuthoringService::
 * saveSectionFields() does for hand-authored Sections, so an imported
 * Section installs identically to one built through the Section Builder.
 *
 * Because the source Entry Type already exists in Craft, installing the
 * generated package doesn't create new Fields/Entry Types -
 * CraftResourceService::createCraftField()/createMatrixEntryType() both
 * already return the existing resource when a matching handle is found -
 * it only links the existing Entry Type into the Matrix field on Enable.
 */
class MatrixEntryTypeImportService extends Component
{
    /**
     * @param array $meta {name, description?, category?, tags?, version?}
     * @throws \Exception if the Entry Type can't be found, or nothing capturable was found.
     */
    public function importFromEntryType(int $entryTypeId, array $meta): PackageRecord
    {
        $entryType = Craft::$app->getEntries()->getEntryTypeById($entryTypeId);
        if (!$entryType) {
            throw new \Exception('Entry Type not found.');
        }

        $craftResourceService = Site7Studio::getInstance()->craftResourceGenerator;
        $layout = $entryType->getFieldLayout();
        $describedFields = $layout ? $craftResourceService->describeFieldLayout($layout) : [];
        $detectedFields = (new ResourceClassifierService())->classifyFieldLayout($describedFields);

        // Kept alongside $describedFields so Shared Resource registration can
        // use the exact live Field object describeField() read, rather than
        // re-resolving it by handle/UID (which proved unreliable for fields
        // whose real Craft field context isn't the global one).
        $liveFieldsByHandle = [];
        foreach ($layout?->getCustomFields() ?? [] as $liveField) {
            $liveFieldsByHandle[$liveField->handle] = $liveField;
        }

        // Capturable fields (Native Resource, plus a resolved Entries/
        // Matrix relationship - Feature Dependency/Nested Resource/Reusable
        // Component) are captured into this package's own fields.yaml/
        // matrix.yaml - Shared/Package/Platform/Plugin/Review-Required
        // resources are always referenced or reported, never duplicated.
        $importableFields = array_values(array_filter($detectedFields, fn($f) => ResourceClassifierService::isCapturable($f['classification'])));
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
            } elseif (in_array($field['classification'], [ResourceClassifierService::PLUGIN_DEPENDENCY, ResourceClassifierService::EXTERNAL_DEPENDENCY], true)) {
                $pluginDependencies[] = ['handle' => $field['handle'], 'requiredPlugin' => $field['requiredPlugin'] ?? 'unknown'];
            } elseif (!ResourceClassifierService::isCapturable($field['classification'])) {
                // Platform Configuration and Review Required fields - not
                // captured as package content and not a dependency either,
                // but recorded so they're still visible on the Package
                // Editor rather than silently disappearing after import.
                $excludedFields[] = [
                    'handle' => $field['handle'],
                    'name' => $field['name'],
                    'type' => $field['type'],
                    'classification' => $field['classification'],
                    'statusLabel' => $field['statusLabel'] ?? $field['classification'],
                    'detail' => $field['detail'] ?? '',
                ];
            }
        }

        $name = trim((string)($meta['name'] ?? $entryType->name));
        if ($name === '') {
            throw new \Exception('A Section name is required.');
        }
        $version = (string)($meta['version'] ?? '1.0.0');

        $validator = new ResourceImportValidator();
        $proposedHandle = $validator->generateUniqueHandle($name);
        $validation = $validator->validateImport('matrix-entry-type', [
            'detectedFields' => $detectedFields,
            'hasCapturableContent' => !empty($importableFields),
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
            'type' => 'section',
            'version' => $version,
            'author' => !empty($meta['author']) ? $meta['author'] : (Craft::$app->getUser()->getIdentity()?->friendlyName ?? 'Site7'),
            'description' => !empty($meta['description']) ? $meta['description'] : "Imported from the Craft Entry Type \"{$entryType->name}\".",
            'category' => $meta['category'] ?: null,
            'tags' => $tags,
            'requires' => [],
            'demoContent' => [],
            'dependencies' => [
                'sharedResources' => array_values(array_unique($sharedResourceHandles)),
                'pluginDependencies' => $pluginDependencies,
            ],
            'excludedFields' => $excludedFields,
            'importedFrom' => [
                'sourceType' => 'matrix-entry-type',
                'sourceId' => $entryType->id,
                'sourceHandle' => $entryType->handle,
                'importedAt' => date('c'),
                'importedBy' => Craft::$app->getUser()->getIdentity()?->friendlyName ?? null,
            ],
        ];

        file_put_contents($packagePath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($packagePath . '/README.md', $this->buildReadme($name, $entryType, $importableFields));

        $this->writeFieldsYaml($packagePath, $name, $importableFields);
        $this->writeMatrixYaml($packagePath, $name, $entryType, $importableFields);
        $this->writeTemplateTwig($packagePath, $handle, $importableFields);

        FileHelper::createDirectory($packagePath . '/preview');
        file_put_contents($packagePath . '/preview/preview-data.yaml', Yaml::dump([
            'block' => array_combine(
                array_map(fn($f) => $f['handle'], $importableFields),
                array_fill(0, count($importableFields), ''),
            ),
        ], 4));

        $packageManager = Site7Studio::getInstance()->packageManager;
        $packageManager->discoverPackages();
        $packageManager->installPackage($handle);
        $packageManager->enablePackage($handle);

        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception('Section was imported but could not be registered.');
        }
        $record->creatorId = Craft::$app->getUser()->getId();
        $record->save();

        Site7Studio::getInstance()->marketplace->syncDependencyRecords($record);

        Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new ResourceImportedEvent([
            'sourceType' => 'matrix-entry-type',
            'sourceId' => $entryType->id,
            'packageHandles' => [$handle],
            'summary' => ['fieldCount' => count($importableFields)],
        ]));

        return $record;
    }

    private function writeFieldsYaml(string $packagePath, string $name, array $fields): void
    {
        $fieldsYaml = [
            'name' => $name . ' Fields',
            'fields' => array_map(fn($f) => array_filter([
                'handle' => $f['handle'],
                'name' => $f['name'],
                'type' => $f['type'],
                'instructions' => $f['instructions'],
                // Only present for Entries/Matrix fields - see
                // CraftResourceService::describeFieldSettings()/
                // createCraftField()'s counterpart read/write.
                'settings' => $f['settings'] ?? [],
            ], fn($v, $k) => $k !== 'settings' || !empty($v), ARRAY_FILTER_USE_BOTH), $fields),
        ];
        file_put_contents($packagePath . '/fields.yaml', Yaml::dump($fieldsYaml, 4));
    }

    private function writeMatrixYaml(string $packagePath, string $name, EntryType $entryType, array $fields): void
    {
        $matrixYaml = [
            'name' => $name . ' Matrix',
            'blocks' => [[
                'handle' => $entryType->handle,
                'name' => $entryType->name,
                'fields' => array_map(fn($f) => $f['handle'], $fields),
            ]],
        ];
        file_put_contents($packagePath . '/matrix.yaml', Yaml::dump($matrixYaml, 4));
    }

    private function writeTemplateTwig(string $packagePath, string $handle, array $fields): void
    {
        $rows = implode("\n", array_map(fn($f) => "    <p>{{ block.{$f['handle']} }}</p>", $fields));
        file_put_contents($packagePath . '/template.twig', "<div class=\"site7-component {$handle}\">\n{$rows}\n</div>\n");
    }

    private function buildReadme(string $name, EntryType $entryType, array $fields): string
    {
        $list = implode("\n", array_map(fn($f) => "- {$f['handle']} ({$f['type']})", $fields));
        return "# {$name}\n\nImported from the Craft Entry Type \"{$entryType->name}\" (`{$entryType->handle}`).\n\nFields:\n\n{$list}\n";
    }
}
