<?php

namespace site7\studio\services\import;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\fields\Categories;
use craft\fields\Tags;
use craft\helpers\FileHelper;
use craft\models\CategoryGroup;
use craft\models\Section;
use craft\models\TagGroup;
use craft\models\Volume;
use site7\studio\events\ResourceImportedEvent;
use site7\studio\models\registry\ResourceNode;
use site7\studio\records\PackageRecord;
use site7\studio\services\CraftResourceRegistry;
use site7\studio\services\scanning\NavigationScanner;
use site7\studio\Site7Studio;

/**
 * Converts a selection of existing Entries + Global Sets into a Starter Kit
 * package - the "Import Existing Website" flow. Mirrors
 * StarterKitGeneratorService::generateFromEntries() (a Starter Kit never
 * stores page content itself, only a reference to a Template package per
 * page), but:
 *  - captures pages via PageImportService instead of TemplateGeneratorService
 *    directly, so both Site7-content and native-content pages are handled;
 *  - always records Structure-section parent/child nesting (pages[].parentSlug)
 *    as a baseline Navigation signal, and whichever Global Sets the user
 *    selects are serialized the same way regardless of name (there is
 *    nothing structurally different about a "nav" Global Set versus any
 *    other one) - see the real Navigation capture bullet below for what
 *    supersedes this when a nav plugin is present;
 *  - captures the settings (not the linked values) of any Asset Volume,
 *    Category Group, or Tag Group actually referenced by a selected page's
 *    or Global Set's field layout, plus the Section-level settings of every
 *    Section a selected page belongs to (Website Starter Kit System Phase
 *    2) - resolved via CraftResourceRegistry's dependency graph (Phase 3)
 *    rather than re-deriving field source-string parsing itself: an Entry
 *    Type's/Global Set's Field dependencies, and each of those Fields'
 *    Asset Volume/Category Group/Tag Group dependencies, are already edges
 *    the Registry computed once when it scanned the project;
 *  - captures real Navigation (Phase 3) when a recognized nav plugin field
 *    (see NavigationScanner) is present on a selected page or Global Set,
 *    replacing the Structure-nesting approximation as the primary source
 *    when available; pages[].parentSlug is still always recorded (cheap,
 *    harmless, and the only signal at all on a project without a nav
 *    plugin) but $notes says so explicitly when a real nav menu was
 *    captured instead.
 */
class WebsiteImportService extends Component
{
    public ?CraftResourceRegistry $registry = null;

    public function init(): void
    {
        parent::init();
        $this->registry ??= new CraftResourceRegistry();
    }

    /**
     * @param int[] $entryIds
     * @param int[] $globalSetIds
     * @param array $meta {name, description?, category?, tags?, version?}
     * @return array{0: PackageRecord, 1: string[], 2: string[]} [the Starter Kit package, per-entry skip reasons, dependency notes]
     * @throws \Exception if none of the given entries could be captured.
     */
    public function importWebsite(array $entryIds, array $globalSetIds, array $meta): array
    {
        $entries = Entry::find()->id($entryIds)->status(null)->all();
        $pageImporter = new PageImportService();

        $pages = [];
        $requiresTemplates = [];
        $skipped = [];
        $notes = [];

        $referencedSectionHandles = [];
        /** @var array<string, ResourceNode> uid => node, deduplicated across every page/Global Set */
        $referencedVolumeNodes = [];
        $referencedCategoryGroupNodes = [];
        $referencedTagGroupNodes = [];
        $navigation = [];
        $navigationScanner = new NavigationScanner();

        foreach ($entries as $entry) {
            /** @var Entry $entry */
            try {
                $templateRecord = $pageImporter->importFromEntry($entry, [
                    'name' => $entry->title,
                    'description' => 'Captured from "' . $entry->title . '" as part of the "' . $meta['name'] . '" import.',
                    'category' => $meta['category'] ?? '',
                    'tags' => '',
                ]);
            } catch (\Throwable $e) {
                $skipped[] = $entry->title . ': ' . $e->getMessage();
                continue;
            }

            $parentSlug = null;
            $parent = $entry->getParent();
            if ($parent instanceof Entry) {
                $parentSlug = $parent->slug;
            }

            $section = $entry->getSection();
            $pages[] = [
                'title' => $entry->title,
                'slug' => $entry->slug,
                'sectionHandle' => $section?->handle,
                'entryTypeHandle' => $entry->getType()->handle,
                'templateHandle' => $templateRecord->handle,
                'parentSlug' => $parentSlug,
            ];
            $requiresTemplates[] = $templateRecord->handle;
            if ($section) {
                $referencedSectionHandles[$section->handle] = true;
            }

            $entryTypeNode = $this->registry->findByHandle(CraftResourceRegistry::KIND_ENTRY_TYPE, $entry->getType()->handle);
            if ($entryTypeNode) {
                $this->collectResourceDependencies($entryTypeNode, $referencedVolumeNodes, $referencedCategoryGroupNodes, $referencedTagGroupNodes);
            }

            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if ($field instanceof Categories) {
                    $notes[] = "\"{$entry->title}\" references Category field '{$field->handle}' - categories are not imported, links will be empty on install.";
                } elseif ($field instanceof Tags) {
                    $notes[] = "\"{$entry->title}\" references Tag field '{$field->handle}' - tags are not imported, links will be empty on install.";
                } elseif ($navigationScanner->isNavigationField($field)) {
                    $menu = $this->captureNavigation($navigationScanner, $field, $entry->getFieldValue($field->handle), 'page', $entry->title);
                    if ($menu) {
                        $navigation[] = $menu;
                        $notes[] = "\"{$entry->title}\" references Navigation field '{$field->handle}' - captured the real menu structure, not just the Structure-nesting approximation.";
                    }
                }
            }
        }

        if (empty($pages)) {
            throw new \Exception('None of the selected pages could be captured: ' . implode('; ', $skipped));
        }

        [$globals, $sharedResourceHandles, $globalNavigation] = $this->describeGlobalSets($globalSetIds, $navigationScanner, $referencedVolumeNodes, $referencedCategoryGroupNodes, $referencedTagGroupNodes);
        $navigation = array_merge($navigation, $globalNavigation);

        $craftSections = $this->describeCraftSections(array_keys($referencedSectionHandles));
        $assetVolumes = $this->describeAssetVolumes($referencedVolumeNodes);
        $categoryGroups = $this->describeCategoryGroups($referencedCategoryGroupNodes);
        $tagGroups = $this->describeTagGroups($referencedTagGroupNodes);

        $name = trim((string)($meta['name'] ?? ''));
        if ($name === '') {
            throw new \Exception('A Starter Kit name is required.');
        }
        $version = (string)($meta['version'] ?? '1.0.0');

        $validator = new ResourceImportValidator();
        $proposedHandle = $validator->generateUniqueHandle($name);
        $validation = $validator->validateImport('website', [
            'hasCapturableContent' => true,
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
            'type' => 'starter-kit',
            'version' => $version,
            'author' => !empty($meta['author']) ? $meta['author'] : (Craft::$app->getUser()->getIdentity()?->friendlyName ?? 'Site7'),
            'description' => $meta['description'] ?? '',
            'category' => $meta['category'] ?: null,
            'tags' => $tags,
            'requires' => array_filter(['templates' => array_values(array_unique($requiresTemplates))]),
            'pages' => $pages,
            'globals' => $globals,
            'craftSections' => $craftSections,
            'assetVolumes' => $assetVolumes,
            'categoryGroups' => $categoryGroups,
            'tagGroups' => $tagGroups,
            'navigation' => $navigation,
            'dependencies' => [
                'sharedResources' => array_values(array_unique($sharedResourceHandles)),
                'pluginDependencies' => [],
            ],
            'importedFrom' => [
                'sourceType' => 'website',
                'sourceId' => null,
                'sourceHandle' => null,
                'importedAt' => date('c'),
                'importedBy' => Craft::$app->getUser()->getIdentity()?->friendlyName ?? null,
            ],
        ];

        file_put_contents($packagePath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($packagePath . '/README.md', $this->buildReadme($name, $pages, $globals));

        FileHelper::createDirectory($packagePath . '/preview');

        $packageManager = Site7Studio::getInstance()->packageManager;
        $packageManager->discoverPackages();
        $packageManager->installPackage($handle);
        $packageManager->enablePackage($handle);

        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception('Starter Kit was imported but could not be registered.');
        }

        Site7Studio::getInstance()->marketplace->syncDependencyRecords($record);

        Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new ResourceImportedEvent([
            'sourceType' => 'website',
            'sourceId' => null,
            'packageHandles' => array_merge([$handle], $requiresTemplates),
            'summary' => ['pageCount' => count($pages), 'globalCount' => count($globals), 'navigationCount' => count($navigation)],
        ]));

        return [$record, $skipped, $notes];
    }

    /**
     * @param int[] $globalSetIds
     * @param array<string, ResourceNode> $referencedVolumeNodes uid => node, merged into by reference
     * @param array<string, ResourceNode> $referencedCategoryGroupNodes uid => node, merged into by reference
     * @param array<string, ResourceNode> $referencedTagGroupNodes uid => node, merged into by reference
     * @return array{0: array<int, array{globalSetHandle: string, name: string, fields: array}>, 1: string[], 2: array} [globals, shared resource handles referenced, captured Navigation entries]
     */
    private function describeGlobalSets(array $globalSetIds, NavigationScanner $navigationScanner, array &$referencedVolumeNodes, array &$referencedCategoryGroupNodes, array &$referencedTagGroupNodes): array
    {
        if (empty($globalSetIds)) {
            return [[], [], []];
        }

        $craftResourceService = Site7Studio::getInstance()->craftResourceGenerator;
        $classifier = new ResourceClassifierService();
        $sharedResourceRegistry = Site7Studio::getInstance()->sharedResourceRegistry;
        $globals = [];
        $sharedResourceHandles = [];
        $navigation = [];

        foreach ($globalSetIds as $globalSetId) {
            $globalSet = Craft::$app->getGlobals()->getSetById((int)$globalSetId);
            if (!$globalSet instanceof GlobalSet) {
                continue;
            }

            $globalSetNode = $this->registry->findByUid(CraftResourceRegistry::KIND_GLOBAL_SET, $globalSet->uid);
            if ($globalSetNode) {
                $this->collectResourceDependencies($globalSetNode, $referencedVolumeNodes, $referencedCategoryGroupNodes, $referencedTagGroupNodes);
            }

            $layout = $globalSet->getFieldLayout();
            $describedFields = $layout ? $craftResourceService->describeFieldLayout($layout) : [];
            $detectedFields = $classifier->classifyFieldLayout($describedFields);

            $liveFieldsByHandle = [];
            foreach ($layout?->getCustomFields() ?? [] as $liveField) {
                $liveFieldsByHandle[$liveField->handle] = $liveField;
                if ($navigationScanner->isNavigationField($liveField)) {
                    $menu = $this->captureNavigation($navigationScanner, $liveField, $globalSet->getFieldValue($liveField->handle), 'globalSet', $globalSet->handle);
                    if ($menu) {
                        $navigation[] = $menu;
                    }
                }
            }

            // Only Feature Resource fields get their value captured - see
            // PageImportService::importNativeContent()'s equivalent note.
            $fields = [];
            foreach ($detectedFields as $field) {
                if ($field['classification'] === ResourceClassifierService::SHARED_RESOURCE) {
                    if (isset($liveFieldsByHandle[$field['handle']])) {
                        $sharedResourceRegistry->registerField($liveFieldsByHandle[$field['handle']], $field);
                    }
                    $sharedResourceHandles[] = $field['handle'];
                    continue;
                }
                if ($field['classification'] !== ResourceClassifierService::FEATURE_RESOURCE) {
                    continue;
                }
                $value = $globalSet->getFieldValue($field['handle']);
                if (is_scalar($value) || $value === null) {
                    $fields[$field['handle']] = $value;
                } elseif (is_object($value) && method_exists($value, '__toString')) {
                    $fields[$field['handle']] = (string)$value;
                }
            }

            $globals[] = [
                'globalSetHandle' => $globalSet->handle,
                'name' => $globalSet->name,
                'fields' => $fields,
            ];
        }

        return [$globals, $sharedResourceHandles, $navigation];
    }

    /**
     * Walks $ownerNode's Field dependencies (an Entry Type's or Global Set's
     * own field layout, already an edge CraftResourceRegistry's graph
     * computed) one hop further to each Field's own Asset Volume/Category
     * Group/Tag Group dependencies, merging every match into the
     * corresponding by-reference map (keyed by uid, so the same Volume/Group
     * referenced by five different pages is only recorded once). Replaces
     * this service's former per-field-type `instanceof`
     * Assets/Categories/Tags branching + manual `sources` parsing entirely -
     * the Registry already resolved those references when it built the
     * graph (via RelationFieldSourceResolver), so this is pure traversal.
     *
     * @param array<string, ResourceNode> $volumeNodes
     * @param array<string, ResourceNode> $categoryGroupNodes
     * @param array<string, ResourceNode> $tagGroupNodes
     */
    private function collectResourceDependencies(ResourceNode $ownerNode, array &$volumeNodes, array &$categoryGroupNodes, array &$tagGroupNodes): void
    {
        foreach ($this->registry->dependenciesOf($ownerNode) as $fieldDependency) {
            if ($fieldDependency->kind !== CraftResourceRegistry::KIND_FIELD) {
                continue;
            }
            foreach ($this->registry->dependenciesOf($fieldDependency) as $resourceDependency) {
                match ($resourceDependency->kind) {
                    CraftResourceRegistry::KIND_ASSET_VOLUME => $volumeNodes[$resourceDependency->key] = $resourceDependency,
                    CraftResourceRegistry::KIND_CATEGORY_GROUP => $categoryGroupNodes[$resourceDependency->key] = $resourceDependency,
                    CraftResourceRegistry::KIND_TAG_GROUP => $tagGroupNodes[$resourceDependency->key] = $resourceDependency,
                    default => null,
                };
            }
        }
    }

    /**
     * Captures a real navigation menu (Website Starter Kit System Phase 3)
     * when $field's currently selected value resolves to one via
     * NavigationScanner::describeMenu() - null (no menu selected, or no
     * recognized nav plugin installed) means nothing to capture, not an
     * error.
     */
    private function captureNavigation(NavigationScanner $navigationScanner, FieldInterface $field, mixed $value, string $ownerType, string $ownerHandle): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $menu = $navigationScanner->describeMenu($value);
        if (!$menu) {
            return null;
        }
        return [
            'fieldHandle' => $field->handle,
            'ownerType' => $ownerType,
            'ownerHandle' => $ownerHandle,
            'source' => 'simple-rp-menu',
            'menu' => $menu,
        ];
    }

    /**
     * @param string[] $sectionHandles
     * @return array<int, array{handle: string, name: string, type: string, propagationMethod: string, enableVersioning: bool, maxLevels: ?int, defaultPlacement: string, siteSettings: array}>
     */
    private function describeCraftSections(array $sectionHandles): array
    {
        $result = [];
        foreach ($sectionHandles as $handle) {
            $sectionNode = $this->registry->findByHandle(CraftResourceRegistry::KIND_SECTION, $handle);
            $section = $sectionNode?->resource;
            if (!$section instanceof Section) {
                continue;
            }
            $result[] = [
                'handle' => $section->handle,
                'name' => $section->name,
                'type' => $section->type,
                'propagationMethod' => $section->propagationMethod->value,
                'enableVersioning' => $section->enableVersioning,
                'maxLevels' => $section->maxLevels,
                'defaultPlacement' => $section->defaultPlacement,
                'siteSettings' => array_map(fn($siteSettings) => [
                    'siteHandle' => Craft::$app->getSites()->getSiteById($siteSettings->siteId)?->handle,
                    'enabledByDefault' => $siteSettings->enabledByDefault,
                    'hasUrls' => $siteSettings->hasUrls,
                    'uriFormat' => $siteSettings->uriFormat,
                    'template' => $siteSettings->template,
                ], array_values($section->getSiteSettings())),
            ];
        }
        return $result;
    }

    /**
     * @param array<string, ResourceNode> $volumeNodes
     * @return array<int, array{handle: string, name: string, fsHandle: ?string, transformFsHandle: ?string, transformSubpath: string, titleTranslationMethod: string}>
     */
    private function describeAssetVolumes(array $volumeNodes): array
    {
        $result = [];
        foreach ($volumeNodes as $node) {
            $volume = $node->resource;
            if (!$volume instanceof Volume) {
                continue;
            }
            $result[] = [
                'handle' => $volume->handle,
                'name' => $volume->name,
                'fsHandle' => $volume->fsHandle,
                'transformFsHandle' => $volume->transformFsHandle,
                'transformSubpath' => $volume->transformSubpath,
                'titleTranslationMethod' => $volume->titleTranslationMethod,
            ];
        }
        return $result;
    }

    /**
     * @param array<string, ResourceNode> $categoryGroupNodes
     * @return array<int, array{handle: string, name: string, maxLevels: ?int, defaultPlacement: string, siteSettings: array}>
     */
    private function describeCategoryGroups(array $categoryGroupNodes): array
    {
        $result = [];
        foreach ($categoryGroupNodes as $node) {
            $group = $node->resource;
            if (!$group instanceof CategoryGroup) {
                continue;
            }
            $result[] = [
                'handle' => $group->handle,
                'name' => $group->name,
                'maxLevels' => $group->maxLevels,
                'defaultPlacement' => $group->defaultPlacement,
                'siteSettings' => array_map(fn($siteSettings) => [
                    'siteHandle' => Craft::$app->getSites()->getSiteById($siteSettings->siteId)?->handle,
                    'hasUrls' => $siteSettings->hasUrls,
                    'uriFormat' => $siteSettings->uriFormat,
                    'template' => $siteSettings->template,
                ], array_values($group->getSiteSettings())),
            ];
        }
        return $result;
    }

    /**
     * @param array<string, ResourceNode> $tagGroupNodes
     * @return array<int, array{handle: string, name: string}>
     */
    private function describeTagGroups(array $tagGroupNodes): array
    {
        $result = [];
        foreach ($tagGroupNodes as $node) {
            $tagGroup = $node->resource;
            if (!$tagGroup instanceof TagGroup) {
                continue;
            }
            $result[] = [
                'handle' => $tagGroup->handle,
                'name' => $tagGroup->name,
            ];
        }
        return $result;
    }

    private function buildReadme(string $name, array $pages, array $globals): string
    {
        $pageList = implode("\n", array_map(fn($p) => "- {$p['title']} ({$p['templateHandle']})" . ($p['parentSlug'] ? " - child of '{$p['parentSlug']}'" : ''), $pages));
        $globalList = $globals ? "\n\nGlobals:\n\n" . implode("\n", array_map(fn($g) => "- {$g['name']} ({$g['globalSetHandle']})", $globals)) : '';
        return "# {$name}\n\nImported via \"Import Existing Website\".\n\nPages:\n\n{$pageList}{$globalList}\n";
    }
}
