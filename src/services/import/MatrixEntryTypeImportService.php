<?php

namespace site7\studio\services\import;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\models\EntryType;
use site7\studio\events\ResourceImportedEvent;
use site7\studio\records\PackageRecord;
use site7\studio\repositories\SectionImportSourceRepository;
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
     * @param array $meta {name, description?, category?, tags?, version?,
     *   ownedFiles?: string[] - Craft-root-relative paths (e.g.
     *   'frontend/src/css/components/ctaBanner.css') the caller explicitly
     *   selected as package-owned (Step 8.1) - see captureOwnedFiles(). Never
     *   inferred; omitted/empty means this package owns no additional files
     *   beyond its template.twig, which is the common case in this project.}
     * @throws \Exception if the Entry Type can't be found, or nothing capturable was found.
     */
    public function importFromEntryType(int $entryTypeId, array $meta): PackageRecord
    {
        $entryType = Craft::$app->getEntries()->getEntryTypeById($entryTypeId);
        if (!$entryType) {
            throw new \Exception('Entry Type not found.');
        }

        // Phase 9.1: a Section Package may only be imported once per source
        // Entry Type, keyed by the Entry Type's own uid (never its numeric
        // id). Guarded here rather than only in ResourceImportController so
        // CraftSectionImportService's per-entry-type delegation to this same
        // method is covered for free too.
        $existingSource = (new SectionImportSourceRepository())->findBySourceUid($entryType->uid);
        if ($existingSource) {
            $existingPackage = PackageRecord::findOne($existingSource->packageId);
            $existingHandle = $existingPackage?->handle ?? $existingSource->sourceHandle;
            throw new \Exception("This Entry Type has already been imported as the Section package '{$existingHandle}'.");
        }

        [$detectedFields, $importableFields, $sharedResourceHandles, $pluginDependencies, $excludedFields] = $this->detectFields($entryType);
        $sourceHash = (new EntryTypeSourceHasher())->computeHash($entryType);

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
        $ownedFiles = $this->captureOwnedFiles($packagePath, (array)($meta['ownedFiles'] ?? []));

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
            'ownedFiles' => $ownedFiles,
            'importedFrom' => [
                'sourceType' => 'matrix-entry-type',
                'sourceId' => $entryType->id,
                'sourceUid' => $entryType->uid,
                'sourceHandle' => $entryType->handle,
                'sourceHash' => $sourceHash,
                'importedAt' => date('c'),
                'importedBy' => Craft::$app->getUser()->getIdentity()?->friendlyName ?? null,
            ],
        ];

        file_put_contents($packagePath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($packagePath . '/README.md', $this->buildReadme($name, $entryType, $importableFields));

        $this->writeFieldsYaml($packagePath, $name, $importableFields);
        $this->writeMatrixYaml($packagePath, $name, $entryType, $importableFields);
        $this->writeTemplateTwig($packagePath, $handle, $entryType->handle, $importableFields);

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

        (new SectionImportSourceRepository())->record($record->id, $entryType->uid, 'matrix-entry-type', $entryType->handle, $sourceHash);

        Site7Studio::getInstance()->marketplace->syncDependencyRecords($record);

        Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new ResourceImportedEvent([
            'sourceType' => 'matrix-entry-type',
            'sourceId' => $entryType->id,
            'packageHandles' => [$handle],
            'summary' => ['fieldCount' => count($importableFields)],
        ]));

        return $record;
    }

    /**
     * The field-detection block shared by importFromEntryType() and (Phase
     * 9.1) SectionUpdateService::updateInPlace() - classifies every field on
     * a live Entry Type's field layout, registering Shared Resources along
     * the way, exactly as importFromEntryType() always has. Extracted
     * unchanged so the Update Package workflow recomputes fields identically
     * to a fresh import rather than duplicating this logic.
     *
     * @return array{0: array, 1: array, 2: string[], 3: array, 4: array} [detectedFields, importableFields, sharedResourceHandles, pluginDependencies, excludedFields]
     */
    public function detectFields(EntryType $entryType): array
    {
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

        return [$detectedFields, $importableFields, $sharedResourceHandles, $pluginDependencies, $excludedFields];
    }

    /**
     * @internal Public so SectionUpdateService can regenerate fields.yaml
     * in-place with the exact same output shape a fresh import writes.
     */
    public function writeFieldsYaml(string $packagePath, string $name, array $fields): void
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

    /**
     * @internal Public so SectionUpdateService can regenerate matrix.yaml in-place.
     */
    public function writeMatrixYaml(string $packagePath, string $name, EntryType $entryType, array $fields): void
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

    /**
     * Captures the package's template.twig from the real, already-styled
     * implementation at templates/_blocks/{sourceHandle}.twig when one
     * exists on disk - read-only, the source file is never modified - so an
     * imported Section package reflects what's actually live instead of a
     * field-name stub. $sourceHandle is the Entry Type's own handle (what
     * _blocks/*.twig is named after), which may differ from $handle (the
     * new package's handle, only used for the stub fallback's wrapper
     * class) when the proposed package handle collided and got suffixed.
     */
    private function writeTemplateTwig(string $packagePath, string $handle, string $sourceHandle, array $fields): void
    {
        if ($this->copyTemplateTwigFromLiveSource($packagePath, $sourceHandle)) {
            return;
        }

        $rows = implode("\n", array_map(fn($f) => "    <p>{{ block.{$f['handle']} }}</p>", $fields));
        file_put_contents($packagePath . '/template.twig', "<div class=\"site7-component {$handle}\">\n{$rows}\n</div>\n");
    }

    /**
     * The real-file half of writeTemplateTwig() above, extracted so
     * SectionUpdateService's Sync From Source (Phase 9.1) can reuse the
     * exact same "copy the real, live templates/_blocks/{sourceHandle}.twig"
     * logic a fresh import uses, rather than a second implementation of it -
     * read-only against the live file, never touches anything else.
     *
     * @return bool true if a real live template was found (and copied); false
     *   if no matching _blocks/ file exists on disk.
     */
    public function copyTemplateTwigFromLiveSource(string $packagePath, string $sourceHandle): bool
    {
        $realTemplatePath = rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/') . '/_blocks/' . $sourceHandle . '.twig';
        if (is_file($realTemplatePath)) {
            copy($realTemplatePath, $packagePath . '/template.twig');
            return true;
        }
        return false;
    }

    /**
     * Copies each explicitly-selected Craft-root-relative path (e.g.
     * 'frontend/src/css/components/ctaBanner.css', as listed by
     * FrontendToolingScanner::listCandidateFrontendFiles() - discovery is a
     * separate step from this, which only ever acts on what the caller
     * explicitly passed) into this package's own directory at the
     * identical relative path - preserving the real relative runtime path,
     * the same rule copyTemplateTwigFromLiveSource() already follows for
     * template.twig. Never inferred from a filename/handle match. A path
     * that's missing on disk, or isn't a plain relative path (no '..'
     * traversal, no leading '/'), is skipped with a logged warning rather
     * than failing the whole import - same "never fatal, degrade
     * gracefully" pattern this service already uses for e.g. an
     * unresolvable Entries field Section reference.
     *
     * @param string[] $selectedPaths
     * @return array<int, array{sourcePath: string, targetPath: string, type: string}>
     */
    public function captureOwnedFiles(string $packagePath, array $selectedPaths): array
    {
        $craftRoot = dirname(rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/'));
        $owned = [];

        foreach ($selectedPaths as $relativePath) {
            $relativePath = str_replace('\\', '/', trim((string)$relativePath));
            if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
                Craft::warning("Skipped invalid owned-file path '{$relativePath}' during import.", __METHOD__);
                continue;
            }

            $absoluteSource = $craftRoot . '/' . $relativePath;
            if (!is_file($absoluteSource)) {
                Craft::warning("Skipped owned-file path '{$relativePath}' - not found on disk.", __METHOD__);
                continue;
            }

            $destination = $packagePath . '/' . $relativePath;
            FileHelper::createDirectory(dirname($destination));
            copy($absoluteSource, $destination);

            $type = match (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION))) {
                'css' => 'frontend-css',
                'js' => 'frontend-js',
                default => 'asset',
            };

            $owned[] = ['sourcePath' => $relativePath, 'targetPath' => $relativePath, 'type' => $type];
        }

        return $owned;
    }

    private function buildReadme(string $name, EntryType $entryType, array $fields): string
    {
        $list = implode("\n", array_map(fn($f) => "- {$f['handle']} ({$f['type']})", $fields));
        return "# {$name}\n\nImported from the Craft Entry Type \"{$entryType->name}\" (`{$entryType->handle}`).\n\nFields:\n\n{$list}\n";
    }
}
