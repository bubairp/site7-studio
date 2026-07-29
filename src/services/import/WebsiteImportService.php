<?php

namespace site7\studio\services\import;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Tags;
use craft\helpers\FileHelper;
use craft\models\CategoryGroup;
use craft\models\Section;
use craft\models\TagGroup;
use craft\models\Volume;
use site7\studio\events\ResourceImportedEvent;
use site7\studio\records\PackageRecord;
use site7\studio\services\CraftResourceScanner;
use site7\studio\Site7Studio;

/**
 * Converts a selection of existing Entries + Global Sets into a Starter Kit
 * package - the "Import Existing Website" flow. Mirrors
 * StarterKitGeneratorService::generateFromEntries() (a Starter Kit never
 * stores page content itself, only a reference to a Template package per
 * page), but:
 *  - captures pages via PageImportService instead of TemplateGeneratorService
 *    directly, so both Site7-content and native-content pages are handled;
 *  - approximates "Navigation" (which has no native Craft 5 concept and no
 *    nav plugin is installed in this project) as Structure-section
 *    parent/child nesting (recorded as pages[].parentSlug, additive to the
 *    existing schema) plus whichever Global Sets the user selects - there is
 *    nothing structurally different about a "nav" Global Set versus any
 *    other one, so all selected Global Sets are serialized the same way;
 *  - captures the settings (not the linked values) of any Asset Volume,
 *    Category Group, or Tag Group actually referenced by a selected page's
 *    or Global Set's field layout, plus the Section-level settings of every
 *    Section a selected page belongs to (Website Starter Kit System Phase
 *    2) - via CraftResourceScanner, the single discovery layer for native
 *    Craft resources. A Category/Tag field's own linked values are still
 *    not captured (recorded as a dependency note, same as before); only the
 *    Group definition itself is now captured, so the target site at least
 *    has somewhere to assign values into after install.
 */
class WebsiteImportService extends Component
{
    public ?CraftResourceScanner $scanner = null;

    public function init(): void
    {
        parent::init();
        $this->scanner ??= new CraftResourceScanner();
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
        $referencedVolumeUids = [];
        $referencedCategoryGroupUids = [];
        $referencedTagGroupUids = [];

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

            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if ($field instanceof Categories) {
                    $notes[] = "\"{$entry->title}\" references Category field '{$field->handle}' - categories are not imported, links will be empty on install.";
                    self::collectRelationSourceUids($field->sources, 'group:', $referencedCategoryGroupUids, fn() => $this->scanner->scanCategoryGroups());
                } elseif ($field instanceof Tags) {
                    $notes[] = "\"{$entry->title}\" references Tag field '{$field->handle}' - tags are not imported, links will be empty on install.";
                    self::collectRelationSourceUids($field->sources, 'taggroup:', $referencedTagGroupUids, fn() => $this->scanner->scanTagGroups());
                } elseif ($field instanceof Assets) {
                    self::collectRelationSourceUids($field->sources, 'volume:', $referencedVolumeUids, fn() => $this->scanner->scanAssetVolumes());
                }
            }
        }

        if (empty($pages)) {
            throw new \Exception('None of the selected pages could be captured: ' . implode('; ', $skipped));
        }

        [$globals, $sharedResourceHandles, $globalResourceRefs] = $this->describeGlobalSets($globalSetIds);
        foreach ($globalResourceRefs['volumeUids'] as $uid) {
            $referencedVolumeUids[$uid] = true;
        }
        foreach ($globalResourceRefs['categoryGroupUids'] as $uid) {
            $referencedCategoryGroupUids[$uid] = true;
        }
        foreach ($globalResourceRefs['tagGroupUids'] as $uid) {
            $referencedTagGroupUids[$uid] = true;
        }

        $craftSections = $this->describeCraftSections(array_keys($referencedSectionHandles));
        $assetVolumes = $this->describeAssetVolumes(array_keys($referencedVolumeUids));
        $categoryGroups = $this->describeCategoryGroups(array_keys($referencedCategoryGroupUids));
        $tagGroups = $this->describeTagGroups(array_keys($referencedTagGroupUids));

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
            'summary' => ['pageCount' => count($pages), 'globalCount' => count($globals)],
        ]));

        return [$record, $skipped, $notes];
    }

    /**
     * @param int[] $globalSetIds
     * @return array{0: array<int, array{globalSetHandle: string, name: string, fields: array}>, 1: string[], 2: array{volumeUids: string[], categoryGroupUids: string[], tagGroupUids: string[]}} [globals, shared resource handles referenced, referenced Volume/Category Group/Tag Group uids]
     */
    private function describeGlobalSets(array $globalSetIds): array
    {
        $emptyRefs = ['volumeUids' => [], 'categoryGroupUids' => [], 'tagGroupUids' => []];
        if (empty($globalSetIds)) {
            return [[], [], $emptyRefs];
        }

        $craftResourceService = Site7Studio::getInstance()->craftResourceGenerator;
        $classifier = new ResourceClassifierService();
        $registry = Site7Studio::getInstance()->sharedResourceRegistry;
        $globals = [];
        $sharedResourceHandles = [];
        $referencedVolumeUids = [];
        $referencedCategoryGroupUids = [];
        $referencedTagGroupUids = [];

        foreach ($globalSetIds as $globalSetId) {
            $globalSet = Craft::$app->getGlobals()->getSetById((int)$globalSetId);
            if (!$globalSet instanceof GlobalSet) {
                continue;
            }

            $layout = $globalSet->getFieldLayout();
            $describedFields = $layout ? $craftResourceService->describeFieldLayout($layout) : [];
            $detectedFields = $classifier->classifyFieldLayout($describedFields);

            $liveFieldsByHandle = [];
            foreach ($layout?->getCustomFields() ?? [] as $liveField) {
                $liveFieldsByHandle[$liveField->handle] = $liveField;
                if ($liveField instanceof Assets) {
                    self::collectRelationSourceUids($liveField->sources, 'volume:', $referencedVolumeUids, fn() => $this->scanner->scanAssetVolumes());
                } elseif ($liveField instanceof Categories) {
                    self::collectRelationSourceUids($liveField->sources, 'group:', $referencedCategoryGroupUids, fn() => $this->scanner->scanCategoryGroups());
                } elseif ($liveField instanceof Tags) {
                    self::collectRelationSourceUids($liveField->sources, 'taggroup:', $referencedTagGroupUids, fn() => $this->scanner->scanTagGroups());
                }
            }

            // Only Feature Resource fields get their value captured - see
            // PageImportService::importNativeContent()'s equivalent note.
            $fields = [];
            foreach ($detectedFields as $field) {
                if ($field['classification'] === ResourceClassifierService::SHARED_RESOURCE) {
                    if (isset($liveFieldsByHandle[$field['handle']])) {
                        $registry->registerField($liveFieldsByHandle[$field['handle']], $field);
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

        return [$globals, $sharedResourceHandles, [
            'volumeUids' => array_keys($referencedVolumeUids),
            'categoryGroupUids' => array_keys($referencedCategoryGroupUids),
            'tagGroupUids' => array_keys($referencedTagGroupUids),
        ]];
    }

    /**
     * Resolves a relation field's `sources` setting (a Categories/Tags/Assets
     * field's "which groups/volumes can be selected" setting) into the set of
     * referenced uids, using the same 'group:{uid}'/'taggroup:{uid}'/
     * 'volume:{uid}' source-string convention Craft core itself uses
     * (mirrors CraftResourceDiscoveryService::resolveEntriesFieldSections'
     * 'section:{uid}' handling for Entries fields). A field configured to
     * allow "all sources" (`sources === '*'`) resolves to every uid of that
     * kind project-wide, via $allWhenWildcard - matching how Craft treats
     * that field at query time (no restriction).
     *
     * Static (no instance state used) so it's directly unit-testable without
     * a live Craft app - see WebsiteImportServiceTest.
     *
     * @param string|array|null $sources
     * @param array<string, true> $uids uid => true, merged into by reference
     * @param callable(): array $allWhenWildcard returns every live resource of this kind, for the '*' case
     */
    private static function collectRelationSourceUids(string|array|null $sources, string $prefix, array &$uids, callable $allWhenWildcard): void
    {
        if ($sources === '*') {
            foreach ($allWhenWildcard() as $resource) {
                if (isset($resource->uid)) {
                    $uids[$resource->uid] = true;
                }
            }
            return;
        }

        foreach ((array)$sources as $source) {
            if (is_string($source) && str_starts_with($source, $prefix)) {
                $uids[substr($source, strlen($prefix))] = true;
            }
        }
    }

    /**
     * @param string[] $sectionHandles
     * @return array<int, array{handle: string, name: string, type: string, propagationMethod: string, enableVersioning: bool, maxLevels: ?int, defaultPlacement: string, siteSettings: array}>
     */
    private function describeCraftSections(array $sectionHandles): array
    {
        $result = [];
        foreach ($sectionHandles as $handle) {
            $section = $this->scanner->sectionScanner->findByHandle($handle);
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
                ], array_values($this->scanner->sectionScanner->siteSettingsFor($section))),
            ];
        }
        return $result;
    }

    /**
     * @param string[] $volumeUids
     * @return array<int, array{handle: string, name: string, fsHandle: ?string, transformFsHandle: ?string, transformSubpath: string, titleTranslationMethod: string}>
     */
    private function describeAssetVolumes(array $volumeUids): array
    {
        $result = [];
        foreach ($volumeUids as $uid) {
            $volume = $this->scanner->assetVolumeScanner->findByUid($uid);
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
     * @param string[] $categoryGroupUids
     * @return array<int, array{handle: string, name: string, maxLevels: ?int, defaultPlacement: string, siteSettings: array}>
     */
    private function describeCategoryGroups(array $categoryGroupUids): array
    {
        $result = [];
        foreach ($categoryGroupUids as $uid) {
            $group = $this->scanner->categoryGroupScanner->findByUid($uid);
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
                ], array_values($this->scanner->categoryGroupScanner->siteSettingsFor($group))),
            ];
        }
        return $result;
    }

    /**
     * @param string[] $tagGroupUids
     * @return array<int, array{handle: string, name: string}>
     */
    private function describeTagGroups(array $tagGroupUids): array
    {
        $result = [];
        foreach ($tagGroupUids as $uid) {
            $tagGroup = $this->scanner->tagGroupScanner->findByUid($uid);
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
