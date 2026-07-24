<?php

namespace site7\studio\services\import;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\fields\Categories;
use craft\fields\Tags;
use craft\helpers\FileHelper;
use site7\studio\events\ResourceImportedEvent;
use site7\studio\records\PackageRecord;
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
 *  - records any referenced Category/Tag field as a dependency note rather
 *    than creating Category/Tag Groups, matching the "Assets import is
 *    future/out of scope" boundary extended to Categories/Tags creation.
 */
class WebsiteImportService extends Component
{
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

            $pages[] = [
                'title' => $entry->title,
                'slug' => $entry->slug,
                'sectionHandle' => $entry->getSection()?->handle,
                'entryTypeHandle' => $entry->getType()->handle,
                'templateHandle' => $templateRecord->handle,
                'parentSlug' => $parentSlug,
            ];
            $requiresTemplates[] = $templateRecord->handle;

            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if ($field instanceof Categories) {
                    $notes[] = "\"{$entry->title}\" references Category field '{$field->handle}' - categories are not imported, links will be empty on install.";
                } elseif ($field instanceof Tags) {
                    $notes[] = "\"{$entry->title}\" references Tag field '{$field->handle}' - tags are not imported, links will be empty on install.";
                }
            }
        }

        if (empty($pages)) {
            throw new \Exception('None of the selected pages could be captured: ' . implode('; ', $skipped));
        }

        [$globals, $sharedResourceHandles] = $this->describeGlobalSets($globalSetIds);

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
     * @return array{0: array<int, array{globalSetHandle: string, name: string, fields: array}>, 1: string[]} [globals, shared resource handles referenced]
     */
    private function describeGlobalSets(array $globalSetIds): array
    {
        if (empty($globalSetIds)) {
            return [[], []];
        }

        $craftResourceService = Site7Studio::getInstance()->craftResourceGenerator;
        $classifier = new ResourceClassifierService();
        $registry = Site7Studio::getInstance()->sharedResourceRegistry;
        $globals = [];
        $sharedResourceHandles = [];

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

        return [$globals, $sharedResourceHandles];
    }

    private function buildReadme(string $name, array $pages, array $globals): string
    {
        $pageList = implode("\n", array_map(fn($p) => "- {$p['title']} ({$p['templateHandle']})" . ($p['parentSlug'] ? " - child of '{$p['parentSlug']}'" : ''), $pages));
        $globalList = $globals ? "\n\nGlobals:\n\n" . implode("\n", array_map(fn($g) => "- {$g['name']} ({$g['globalSetHandle']})", $globals)) : '';
        return "# {$name}\n\nImported via \"Import Existing Website\".\n\nPages:\n\n{$pageList}{$globalList}\n";
    }
}
