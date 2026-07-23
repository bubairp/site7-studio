<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use site7\studio\Site7Studio;
use site7\studio\records\PackageRecord;
use Symfony\Component\Yaml\Yaml;

/**
 * Backs the Package Authoring Platform's New Package wizard and Package
 * Editor. Scaffolds a bare package skeleton (manifest.json + README +
 * preview folder) for any of the four package types and lets its General
 * metadata be edited afterward - both through the same workflow regardless
 * of type, per Phase 11's "one consistent workflow" objective.
 *
 * A freshly-created package has no Content yet (no matrix.yaml/fields.yaml/
 * template.twig for Sections, no requires/demoContent for the others) -
 * that's this milestone's explicit scope boundary (General + Package
 * Information only). It installs as an empty shell if enabled early, which
 * is exactly what its "draft" authoring status is meant to signal.
 */
class PackageAuthoringService extends Component
{
    public const VALID_TYPES = ['section', 'pattern', 'template', 'starter-kit'];

    /**
     * @param array $meta {type, name, handle?, description?, category?, tags?, version?, author?}
     * @throws \Exception if the type is unsupported or the handle is already taken.
     */
    public function createPackage(array $meta): PackageRecord
    {
        $type = strtolower((string)($meta['type'] ?? ''));
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \Exception('Unsupported package type.');
        }

        $name = trim((string)($meta['name'] ?? ''));
        if ($name === '') {
            throw new \Exception('A package name is required.');
        }

        $handle = trim((string)($meta['handle'] ?? '')) ?: StringHelper::toKebabCase($name);
        $basePath = Craft::getAlias('@packages');
        if (is_dir($basePath . '/' . $handle)) {
            throw new \Exception("A package with the handle '{$handle}' already exists.");
        }

        $packagePath = rtrim($basePath, '/') . '/' . $handle;
        FileHelper::createDirectory($packagePath);
        FileHelper::createDirectory($packagePath . '/preview');

        $tags = array_values(array_filter(array_map('trim', explode(',', (string)($meta['tags'] ?? '')))));

        $manifest = [
            'schemaVersion' => '1',
            'handle' => $handle,
            'name' => $name,
            'type' => $type,
            'version' => $meta['version'] ?? '1.0.0',
            'author' => $meta['author'] ?? (Craft::$app->getUser()->getIdentity()?->friendlyName ?? ''),
            'description' => $meta['description'] ?? '',
            'category' => $meta['category'] ?? null,
            'tags' => $tags,
            'requires' => [],
            'demoContent' => [],
            'pages' => [],
            'dependencies' => [],
        ];

        file_put_contents($packagePath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($packagePath . '/README.md', "# {$name}\n\nCreated via the Package Authoring Platform.\n");

        $packageManager = Site7Studio::getInstance()->packageManager;
        $packageManager->discoverPackages();

        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception('Package was created on disk but could not be registered.');
        }

        // Newly-authored packages start in Draft, distinct from the generic
        // discovery default ("published", for packages that already existed
        // on disk before this feature did - see the authoringStatus column's
        // own DB default and its migration).
        $record->authoringStatus = 'draft';
        $record->save();

        return $record;
    }

    /**
     * Updates a package's General metadata - both its manifest.json (the
     * portable, single-source-of-truth definition) and its PackageRecord
     * (the DB-side mirror the Library/Package Engine actually query).
     *
     * @param array $fields {name?, description?, category?, tags?, author?, version?,
     *   displayName?, company?, website?, supportUrl?, documentationUrl?, license?,
     *   pricingType?, minimumCraftVersion?, minimumSite7Version?, keywords?}
     *   The Publishing-metadata keys (displayName..keywords) are optional and additive -
     *   see PackageManifest's own docblock; they're written the same way as every
     *   other field here (manifest.json is the source of truth, PackageRecord mirrors
     *   what's actually queryable elsewhere in the CP).
     * @throws \Exception if the package can't be found.
     */
    public function updatePackage(string $handle, array $fields): PackageRecord
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception('Package not found.');
        }

        $packagePath = $packageManager->getPackagePath($handle);
        if (!$packagePath || !file_exists($packagePath . '/manifest.json')) {
            throw new \Exception('Package manifest not found on disk.');
        }

        $manifestData = json_decode(file_get_contents($packagePath . '/manifest.json'), true) ?: [];

        foreach ([
            'name', 'description', 'category', 'author', 'version',
            'displayName', 'company', 'website', 'supportUrl', 'documentationUrl',
            'license', 'pricingType', 'minimumCraftVersion', 'minimumSite7Version',
        ] as $key) {
            if (array_key_exists($key, $fields)) {
                $manifestData[$key] = $fields[$key];
            }
        }
        if (array_key_exists('tags', $fields)) {
            $manifestData['tags'] = array_values(array_filter(array_map('trim', explode(',', (string)$fields['tags']))));
        }
        if (array_key_exists('keywords', $fields)) {
            $manifestData['keywords'] = array_values(array_filter(array_map('trim', explode(',', (string)$fields['keywords']))));
        }

        file_put_contents($packagePath . '/manifest.json', json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (isset($manifestData['name'])) {
            $record->name = $manifestData['name'];
        }
        if (isset($manifestData['description'])) {
            $record->description = $manifestData['description'];
        }
        if (isset($manifestData['category'])) {
            $record->category = $manifestData['category'];
        }
        if (isset($manifestData['author'])) {
            $record->author = $manifestData['author'];
        }
        if (isset($manifestData['version'])) {
            $record->version = $manifestData['version'];
        }
        if (isset($manifestData['tags'])) {
            $record->tags = implode(',', $manifestData['tags']);
        }
        // requiredCraftVersion/minimumStudioVersion have existed on this table
        // since the original package-tables migration but nothing populated
        // them until now - see PackageManifest's minimumCraftVersion/minimumSite7Version.
        if (isset($manifestData['minimumCraftVersion'])) {
            $record->requiredCraftVersion = $manifestData['minimumCraftVersion'];
        }
        if (isset($manifestData['minimumSite7Version'])) {
            $record->minimumStudioVersion = $manifestData['minimumSite7Version'];
        }
        $record->save();

        return $record;
    }

    public const PREVIEW_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /**
     * Saves an uploaded preview thumbnail as packages/<handle>/preview/preview.<ext>,
     * removing any previous preview image of a different extension so there's
     * never more than one on disk at a time.
     *
     * @throws \Exception if the package doesn't exist or the file isn't a
     *     supported image type.
     */
    public function savePreviewImage(string $handle, \craft\web\UploadedFile $file): void
    {
        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($handle);
        if (!$packagePath) {
            throw new \Exception('Package not found.');
        }

        $extension = strtolower((string)$file->getExtension());
        if (!in_array($extension, self::PREVIEW_IMAGE_EXTENSIONS, true)) {
            throw new \Exception('Preview image must be one of: ' . implode(', ', self::PREVIEW_IMAGE_EXTENSIONS) . '.');
        }

        $previewDir = $packagePath . '/preview';
        FileHelper::createDirectory($previewDir);

        foreach (self::PREVIEW_IMAGE_EXTENSIONS as $existingExt) {
            $existingPath = $previewDir . '/preview.' . $existingExt;
            if (file_exists($existingPath)) {
                unlink($existingPath);
            }
        }

        $file->saveAs($previewDir . '/preview.' . $extension);
    }

    /**
     * The Section Builder's read side: merges fields.yaml's field definitions
     * with preview/preview-data.yaml's demo values into one editable list.
     * Section fields are all PlainText today (this plugin's established MVP
     * scope), so there's nothing type-specific to surface per field yet.
     *
     * @return array<int, array{handle: string, name: string, instructions: string, demoValue: string}>
     */
    public function getSectionFields(string $handle): array
    {
        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($handle);
        if (!$packagePath) {
            return [];
        }

        $fields = [];
        $fieldsYamlPath = $packagePath . '/fields.yaml';
        if (file_exists($fieldsYamlPath)) {
            $data = Yaml::parseFile($fieldsYamlPath);
            foreach ($data['fields'] ?? [] as $def) {
                if (empty($def['handle'])) {
                    continue;
                }
                $fields[$def['handle']] = [
                    'handle' => $def['handle'],
                    'name' => $def['name'] ?? $def['handle'],
                    'instructions' => $def['instructions'] ?? '',
                    'demoValue' => '',
                ];
            }
        }

        $previewDataPath = $packagePath . '/preview/preview-data.yaml';
        if (file_exists($previewDataPath)) {
            $data = Yaml::parseFile($previewDataPath);
            foreach ($data['block'] ?? [] as $fieldHandle => $value) {
                if (isset($fields[$fieldHandle])) {
                    $fields[$fieldHandle]['demoValue'] = (string)$value;
                }
            }
        }

        return array_values($fields);
    }

    /**
     * The Section Builder's write side. Regenerates fields.yaml, matrix.yaml,
     * and preview/preview-data.yaml from the submitted field list - the same
     * three files CraftResourceService reads on Install, so a Section built
     * this way installs exactly like a hand-written one. template.twig is
     * only auto-generated the first time, so any hand-written markup from a
     * later editing pass survives re-saving the field list.
     *
     * @param array $fields List of {handle, name, instructions?, demoValue?}; blank rows are dropped.
     * @throws \Exception if the package isn't a Section, or no valid fields were given.
     */
    public function saveSectionFields(string $handle, array $fields): void
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $packagePath = $packageManager->getPackagePath($handle);
        $record = $packageManager->getPackageByHandle($handle);
        if (!$packagePath || !$record) {
            throw new \Exception('Package not found.');
        }
        if ($record->type !== 'section') {
            throw new \Exception('This package is not a Section.');
        }

        $cleanFields = [];
        foreach ($fields as $field) {
            $fieldHandle = trim((string)($field['handle'] ?? ''));
            $name = trim((string)($field['name'] ?? ''));
            if ($fieldHandle === '' || $name === '') {
                continue;
            }
            $cleanFields[] = [
                'handle' => $fieldHandle,
                'name' => $name,
                'instructions' => (string)($field['instructions'] ?? ''),
                'demoValue' => (string)($field['demoValue'] ?? ''),
            ];
        }

        if (empty($cleanFields)) {
            throw new \Exception('Add at least one field.');
        }

        $fieldsYaml = [
            'name' => $record->name . ' Fields',
            'fields' => array_map(fn($f) => [
                'handle' => $f['handle'],
                'name' => $f['name'],
                'type' => 'PlainText',
                'instructions' => $f['instructions'],
            ], $cleanFields),
        ];
        file_put_contents($packagePath . '/fields.yaml', Yaml::dump($fieldsYaml, 4));

        // Reuse the existing block/Entry Type handle if matrix.yaml already
        // exists, so re-saving doesn't rename an already-installed Entry Type
        // out from under any live content.
        $blockHandle = null;
        $matrixYamlPath = $packagePath . '/matrix.yaml';
        if (file_exists($matrixYamlPath)) {
            $existing = Yaml::parseFile($matrixYamlPath);
            $blockHandle = $existing['blocks'][0]['handle'] ?? null;
        }
        if (!$blockHandle) {
            $blockHandle = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $handle))));
        }

        $matrixYaml = [
            'name' => $record->name . ' Matrix',
            'blocks' => [[
                'handle' => $blockHandle,
                'name' => $record->name,
                'fields' => array_map(fn($f) => $f['handle'], $cleanFields),
            ]],
        ];
        file_put_contents($matrixYamlPath, Yaml::dump($matrixYaml, 4));

        $demoData = ['block' => array_combine(
            array_map(fn($f) => $f['handle'], $cleanFields),
            array_map(fn($f) => $f['demoValue'], $cleanFields)
        )];
        FileHelper::createDirectory($packagePath . '/preview');
        file_put_contents($packagePath . '/preview/preview-data.yaml', Yaml::dump($demoData, 4));

        $templatePath = $packagePath . '/template.twig';
        if (!file_exists($templatePath)) {
            $rows = implode("\n", array_map(fn($f) => "    <p>{{ block.{$f['handle']} }}</p>", $cleanFields));
            file_put_contents($templatePath, "<div class=\"site7-component {$handle}\">\n{$rows}\n</div>\n");
        }
    }

    /**
     * The Pattern Builder's left-sidebar Section library: every installed
     * Section, with its field definitions embedded so the Builder can render
     * the right-sidebar "Default Values" inputs for any Section dropped onto
     * the canvas without a further round-trip.
     *
     * @return array<int, array{handle: string, name: string, category: string, previewImageUrl: string, fields: array}>
     */
    public function getAvailableSections(): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $sections = [];

        foreach ($packageManager->getAllPackages() as $pkg) {
            if (strtolower($pkg->type) !== 'section') {
                continue;
            }
            $sections[] = [
                'handle' => $pkg->handle,
                'name' => $pkg->name,
                'category' => $pkg->category ?: 'Uncategorized',
                'previewImageUrl' => UrlHelper::cpUrl('site7-studio/library/package/' . $pkg->handle . '/preview-image'),
                'fields' => $this->getSectionFieldDefs($pkg->handle),
            ];
        }

        return $sections;
    }

    /**
     * @return array<int, array{handle: string, name: string}>
     */
    public function getSectionFieldDefs(string $sectionHandle): array
    {
        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($sectionHandle);
        if (!$packagePath) {
            return [];
        }

        $fieldsYamlPath = $packagePath . '/fields.yaml';
        if (!file_exists($fieldsYamlPath)) {
            return [];
        }

        $data = Yaml::parseFile($fieldsYamlPath);
        $out = [];
        foreach ($data['fields'] ?? [] as $def) {
            if (empty($def['handle'])) {
                continue;
            }
            $out[] = ['handle' => $def['handle'], 'name' => $def['name'] ?? $def['handle']];
        }
        return $out;
    }

    /**
     * The Pattern Builder's canvas, hydrated from the Pattern's own manifest -
     * requires.sections (order) and demoContent (each instance's own Default
     * Values, which "belong to the Pattern only" and never touch the
     * referenced Section package itself).
     *
     * @return array<int, array{sectionHandle: string, sectionName: string, defaultValues: array}>
     */
    public function getPatternComposition(string $handle): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($handle);
        $manifest = $record?->getManifest();
        if (!$manifest) {
            return [];
        }

        $composition = [];
        foreach ($manifest->requires['sections'] ?? [] as $sectionHandle) {
            $sectionRecord = $packageManager->getPackageByHandle($sectionHandle);
            $snakeHandle = str_replace('-', '_', $sectionHandle);
            $composition[] = [
                'sectionHandle' => $sectionHandle,
                'sectionName' => $sectionRecord->name ?? $sectionHandle,
                'defaultValues' => $manifest->demoContent[$sectionHandle] ?? $manifest->demoContent[$snakeHandle] ?? [],
            ];
        }

        return $composition;
    }

    /**
     * Saves the Pattern Builder's canvas back to the manifest. Only
     * requires.sections and demoContent change - a Pattern never duplicates
     * a Section's own definition, only references it by handle, per Phase
     * 11.2's "Patterns do NOT create new Sections" rule.
     *
     * Note: demoContent is keyed by section handle (matching the existing,
     * frozen manifest schema also used by Templates) - if the same Section
     * appears more than once in a Pattern, its Default Values are shared
     * across every instance. That's an existing schema limitation, not one
     * introduced here.
     *
     * @param array $sections Ordered list of {sectionHandle, defaultValues?}; invalid/unknown Section handles are dropped.
     * @throws \Exception if the package isn't a Pattern, or no valid Sections were given.
     */
    public function savePatternComposition(string $handle, array $sections): void
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $packagePath = $packageManager->getPackagePath($handle);
        $record = $packageManager->getPackageByHandle($handle);
        if (!$packagePath || !$record) {
            throw new \Exception('Package not found.');
        }
        if ($record->type !== 'pattern') {
            throw new \Exception('This package is not a Pattern.');
        }

        $sectionHandles = [];
        $demoContent = [];
        foreach ($sections as $section) {
            $sectionHandle = trim((string)($section['sectionHandle'] ?? ''));
            if ($sectionHandle === '') {
                continue;
            }
            $sectionRecord = $packageManager->getPackageByHandle($sectionHandle);
            if (!$sectionRecord || strtolower($sectionRecord->type) !== 'section') {
                continue;
            }
            $sectionHandles[] = $sectionHandle;
            $defaultValues = $section['defaultValues'] ?? [];
            $demoContent[$sectionHandle] = is_array($defaultValues) ? $defaultValues : [];
        }

        if (empty($sectionHandles)) {
            throw new \Exception('Add at least one Section to the Pattern.');
        }

        $manifestData = json_decode(file_get_contents($packagePath . '/manifest.json'), true) ?: [];
        $manifestData['requires']['sections'] = $sectionHandles;
        $manifestData['demoContent'] = $demoContent;

        file_put_contents($packagePath . '/manifest.json', json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The Template Builder's left sidebar library - every installed Section
     * AND Pattern, since a Template composes both. Sections carry their own
     * field defs (for the right sidebar's Default Value overrides); Patterns
     * don't - a Template never overrides a Pattern's own content, only
     * references it by handle, per "Templates must never duplicate package
     * definitions."
     *
     * @return array<int, array{handle: string, name: string, type: string, category: string, previewImageUrl: string, fields: array}>
     */
    public function getAvailableSectionsAndPatterns(): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $items = [];

        foreach ($packageManager->getAllPackages() as $pkg) {
            $type = strtolower($pkg->type);
            if ($type !== 'section' && $type !== 'pattern') {
                continue;
            }
            $items[] = [
                'handle' => $pkg->handle,
                'name' => $pkg->name,
                'type' => $type,
                'category' => $pkg->category ?: 'Uncategorized',
                'previewImageUrl' => UrlHelper::cpUrl('site7-studio/library/package/' . $pkg->handle . '/preview-image'),
                'editUrl' => UrlHelper::cpUrl('site7-studio/packages/' . $pkg->handle . '/edit'),
                'fields' => $type === 'section' ? $this->getSectionFieldDefs($pkg->handle) : [],
            ];
        }

        return $items;
    }

    /**
     * The Template Builder's canvas, hydrated from the Template's own
     * manifest. requires.patterns and requires.sections are stored as two
     * separate ordered lists (Phase 9's frozen schema, where all Patterns
     * install before any bare Sections) - read back here as Patterns first,
     * then Sections, which is also each list's true install order.
     *
     * @return array<int, array{type: string, handle: string, name: string, defaultValues: array}>
     */
    public function getTemplateComposition(string $handle): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($handle);
        $manifest = $record?->getManifest();
        if (!$manifest) {
            return [];
        }

        $composition = [];
        foreach ($manifest->requires['patterns'] ?? [] as $patternHandle) {
            $patternRecord = $packageManager->getPackageByHandle($patternHandle);
            $composition[] = [
                'type' => 'pattern',
                'handle' => $patternHandle,
                'name' => $patternRecord->name ?? $patternHandle,
                'defaultValues' => [],
            ];
        }
        foreach ($manifest->requires['sections'] ?? [] as $sectionHandle) {
            $sectionRecord = $packageManager->getPackageByHandle($sectionHandle);
            $snakeHandle = str_replace('-', '_', $sectionHandle);
            $composition[] = [
                'type' => 'section',
                'handle' => $sectionHandle,
                'name' => $sectionRecord->name ?? $sectionHandle,
                'defaultValues' => $manifest->demoContent[$sectionHandle] ?? $manifest->demoContent[$snakeHandle] ?? [],
            ];
        }

        return $composition;
    }

    /**
     * Saves the Template Builder's canvas back to the manifest. The canvas
     * lets Sections and Patterns be freely interleaved and reordered for
     * visual composition, but requires.patterns/requires.sections stay two
     * separate ordered lists on disk (the existing, frozen schema) - so on
     * save this splits the single visual order into "all Patterns, in the
     * order they appeared" + "all bare Sections, in the order they
     * appeared." Install order is always Patterns-then-Sections regardless
     * of how they were interleaved in the canvas; this is an existing
     * platform limitation (see TemplateGeneratorService::detectPatternReferences),
     * not one introduced here.
     *
     * demoContent is only ever written for bare Section items - a Template
     * never overrides a Pattern's own Default Values, only references the
     * Pattern by handle.
     *
     * @param array $items Ordered list of {type: 'section'|'pattern', handle, defaultValues?}; invalid/unknown/mismatched-type entries are dropped.
     * @throws \Exception if the package isn't a Template, or nothing valid was given.
     */
    public function saveTemplateComposition(string $handle, array $items): void
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $packagePath = $packageManager->getPackagePath($handle);
        $record = $packageManager->getPackageByHandle($handle);
        if (!$packagePath || !$record) {
            throw new \Exception('Package not found.');
        }
        if ($record->type !== 'template') {
            throw new \Exception('This package is not a Template.');
        }

        $patternHandles = [];
        $sectionHandles = [];
        $demoContent = [];

        foreach ($items as $item) {
            $itemType = strtolower((string)($item['type'] ?? ''));
            $itemHandle = trim((string)($item['handle'] ?? ''));
            if ($itemHandle === '') {
                continue;
            }
            $itemRecord = $packageManager->getPackageByHandle($itemHandle);
            if (!$itemRecord || strtolower($itemRecord->type) !== $itemType) {
                continue;
            }

            if ($itemType === 'pattern') {
                $patternHandles[] = $itemHandle;
            } elseif ($itemType === 'section') {
                $sectionHandles[] = $itemHandle;
                $defaultValues = $item['defaultValues'] ?? [];
                $demoContent[$itemHandle] = is_array($defaultValues) ? $defaultValues : [];
            }
        }

        if (empty($patternHandles) && empty($sectionHandles)) {
            throw new \Exception('Add at least one Section or Pattern to the Template.');
        }

        $manifestData = json_decode(file_get_contents($packagePath . '/manifest.json'), true) ?: [];
        $manifestData['requires']['patterns'] = $patternHandles;
        $manifestData['requires']['sections'] = $sectionHandles;
        $manifestData['demoContent'] = $demoContent;

        file_put_contents($packagePath . '/manifest.json', json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The Starter Kit Builder's left sidebar library - every installed
     * Template. sourceEntryType (when present - i.e. the Template was
     * captured via "Save as Template") is passed through so the Builder can
     * default a dropped Template's Page to the matching Entry Type.
     *
     * @return array<int, array{handle: string, name: string, category: string, previewImageUrl: string, sourceEntryType: ?string}>
     */
    public function getAvailableTemplates(): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $templates = [];

        foreach ($packageManager->getAllPackages() as $pkg) {
            if (strtolower($pkg->type) !== 'template') {
                continue;
            }
            $manifest = $pkg->getManifest();
            $templates[] = [
                'handle' => $pkg->handle,
                'name' => $pkg->name,
                'category' => $pkg->category ?: 'Uncategorized',
                'previewImageUrl' => UrlHelper::cpUrl('site7-studio/library/package/' . $pkg->handle . '/preview-image'),
                'sourceEntryType' => $manifest?->sourceEntryType,
            ];
        }

        return $templates;
    }

    /**
     * Entry Types eligible to receive Site7 content, for the Starter Kit
     * Builder's per-Page Entry Type picker - the same set (and eligibility
     * rule) "Create Page from Template" already uses.
     *
     * @return array<int, array{entryTypeId: int, entryTypeHandle: string, entryTypeName: string, sectionId: int, sectionHandle: string, sectionName: string, showSlugField: bool, preferred: bool}>
     */
    public function getEligibleEntryTypesForStarterKit(): array
    {
        return (new TemplateInsertionService())->getEligibleEntryTypes();
    }

    /**
     * The Starter Kit Builder's canvas, hydrated from the Starter Kit's own
     * manifest.pages - Phase 10's frozen schema. Each Page is a structural
     * reference only (title/slug/section/entry type + the Template handle
     * it was built from) - a Starter Kit never stores page content itself,
     * per "Starter Kits must never duplicate Template definitions."
     *
     * @return array<int, array{title: string, slug: string, sectionHandle: ?string, entryTypeHandle: ?string, templateHandle: string, templateName: string}>
     */
    public function getStarterKitComposition(string $handle): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($handle);
        $manifest = $record?->getManifest();
        if (!$manifest) {
            return [];
        }

        $composition = [];
        foreach ($manifest->pages ?? [] as $page) {
            $templateHandle = (string)($page['templateHandle'] ?? '');
            $templateRecord = $packageManager->getPackageByHandle($templateHandle);
            $composition[] = [
                'title' => (string)($page['title'] ?? ''),
                'slug' => (string)($page['slug'] ?? ''),
                'sectionHandle' => $page['sectionHandle'] ?? null,
                'entryTypeHandle' => $page['entryTypeHandle'] ?? null,
                'templateHandle' => $templateHandle,
                'templateName' => $templateRecord->name ?? $templateHandle,
            ];
        }

        return $composition;
    }

    /**
     * Saves the Starter Kit Builder's canvas back to the manifest. Only
     * pages and requires.templates change - a Page references a Template by
     * handle only, never duplicating its definition.
     *
     * @param array $pages Ordered list of {title, slug?, entryTypeHandle?, templateHandle}; invalid/unknown Template handles are dropped.
     * @throws \Exception if the package isn't a Starter Kit, or no valid Pages were given.
     */
    public function saveStarterKitComposition(string $handle, array $pages): void
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $packagePath = $packageManager->getPackagePath($handle);
        $record = $packageManager->getPackageByHandle($handle);
        if (!$packagePath || !$record) {
            throw new \Exception('Package not found.');
        }
        if ($record->type !== 'starter-kit') {
            throw new \Exception('This package is not a Starter Kit.');
        }

        $entryTypesByHandle = [];
        foreach ((new TemplateInsertionService())->getEligibleEntryTypes() as $option) {
            $entryTypesByHandle[$option['entryTypeHandle']] = $option;
        }

        $normalizedPages = [];
        $requiresTemplates = [];

        foreach ($pages as $page) {
            $templateHandle = trim((string)($page['templateHandle'] ?? ''));
            $title = trim((string)($page['title'] ?? ''));
            if ($templateHandle === '' || $title === '') {
                continue;
            }
            $templateRecord = $packageManager->getPackageByHandle($templateHandle);
            if (!$templateRecord || strtolower($templateRecord->type) !== 'template') {
                continue;
            }

            $entryTypeHandle = trim((string)($page['entryTypeHandle'] ?? ''));
            $entryTypeOption = $entryTypesByHandle[$entryTypeHandle] ?? null;

            $normalizedPages[] = [
                'title' => $title,
                'slug' => trim((string)($page['slug'] ?? '')) ?: null,
                'sectionHandle' => $entryTypeOption['sectionHandle'] ?? null,
                'entryTypeHandle' => $entryTypeOption['entryTypeHandle'] ?? null,
                'templateHandle' => $templateHandle,
            ];
            $requiresTemplates[] = $templateHandle;
        }

        if (empty($normalizedPages)) {
            throw new \Exception('Add at least one Page to the Starter Kit.');
        }

        $manifestData = json_decode(file_get_contents($packagePath . '/manifest.json'), true) ?: [];
        $manifestData['pages'] = $normalizedPages;
        $manifestData['requires']['templates'] = array_values(array_unique($requiresTemplates));

        file_put_contents($packagePath . '/manifest.json', json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
