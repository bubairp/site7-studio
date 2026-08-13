<?php

namespace site7\studio\services\import;

use craft\elements\Entry;
use site7\studio\services\ComposerDependencyScanner;
use site7\studio\services\FrontendToolingScanner;
use site7\studio\services\scanning\NavigationScanner;
use site7\studio\Site7Studio;

/**
 * Phase 9.3's Starter Kit Dependency Analysis / Summary - the website-level
 * analog of Phase 9.2's PageDependencyResolverService. Given the wizard's
 * current page selection, computes the counts the "Starter Kit Summary"
 * preview shows, before anything is captured/written.
 *
 * Deliberately independent of WebsiteImportService's own capture logic -
 * same "mirror, don't call the writer" rule ResourceAnalyzerService's own
 * docblock already establishes for every other analyze*() method (the
 * writer's job is to write to disk and register Shared Resources as a
 * side effect; a preview must never do either). Composer/npm/plugin counts
 * are the one exception - reused directly from ComposerDependencyScanner/
 * FrontendToolingScanner, which are already pure, side-effect-free project
 * scans (not tied to WebsiteImportService's write path).
 */
class StarterKitDependencyResolverService
{
    /**
     * @param int[] $entryIds
     * @param int[] $globalSetIds
     * @return array{sections: int, pages: int, categories: int, assets: int, components: int, patterns: int, globals: int, navigation: int, templates: int, composerPackages: int, npmPackages: int, plugins: int}
     */
    public function resolve(array $entryIds, array $globalSetIds): array
    {
        $entries = Entry::find()->id($entryIds)->status(null)->all();
        $matrixHandle = $this->getMatrixFieldHandle();
        $navigationScanner = new NavigationScanner();

        $sectionHandles = [];
        $componentEntryTypeHandles = [];
        $categoryIds = [];
        $assetIds = [];
        $navigationMenus = [];

        foreach ($entries as $entry) {
            /** @var Entry $entry */
            $section = $entry->getSection();
            if ($section) {
                $sectionHandles[$section->handle] = true;
            }

            $this->collectFieldReferences($entry, $navigationScanner, $categoryIds, $assetIds, $navigationMenus, $matrixHandle ? [$matrixHandle] : []);

            if ($matrixHandle && $entry->getFieldLayout()?->getFieldByHandle($matrixHandle)) {
                $fieldValue = $entry->getFieldValue($matrixHandle);
                $blocks = $fieldValue ? $fieldValue->status(null)->drafts(null)->savedDraftsOnly(false)->all() : [];
                foreach ($blocks as $block) {
                    $componentEntryTypeHandles[$block->getType()->handle] = true;
                    $this->collectFieldReferences($block, $navigationScanner, $categoryIds, $assetIds, $navigationMenus, []);
                }
            }
        }

        $patternCount = $this->countReferencedPatterns(array_keys($componentEntryTypeHandles));

        $composerScanner = new ComposerDependencyScanner();
        $frontendScanner = new FrontendToolingScanner();
        $frontendDetection = $frontendScanner->detect();
        $npmPackages = $frontendDetection ? $frontendScanner->captureNpmDependencies($frontendDetection['root']) : [];

        return [
            'sections' => count($sectionHandles),
            'pages' => count($entries),
            'categories' => count($categoryIds),
            'assets' => count($assetIds),
            'components' => count($componentEntryTypeHandles),
            'patterns' => $patternCount,
            'globals' => count(array_unique($globalSetIds)),
            'navigation' => count($navigationMenus),
            'templates' => count($entries),
            'composerPackages' => count($composerScanner->captureComposerPluginDependencies()),
            'npmPackages' => count($npmPackages),
            'plugins' => count($composerScanner->captureComposerPluginDependencies()),
        ];
    }

    /**
     * Same field-walk shape as PageDependencyResolverService (Phase 9.2),
     * but only collecting distinct ids/keys for counting - not building a
     * full preview payload.
     *
     * @param array<int, true> $categoryIds by-reference dedup set, keyed by Category id
     * @param array<int, true> $assetIds by-reference dedup set, keyed by Asset id
     * @param array<string, true> $navigationMenus by-reference dedup set, keyed by menu handle
     */
    private function collectFieldReferences(Entry $element, NavigationScanner $navigationScanner, array &$categoryIds, array &$assetIds, array &$navigationMenus, array $skipHandles): void
    {
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (in_array($field->handle, $skipHandles, true)) {
                continue;
            }

            if ($field instanceof \craft\fields\Categories || $field instanceof \craft\fields\Tags) {
                $value = $element->getFieldValue($field->handle);
                $items = $value === null ? [] : (method_exists($value, 'all') ? $value->all() : []);
                foreach ($items as $item) {
                    $categoryIds[$item->id] = true;
                }
                continue;
            }

            if ($field instanceof \craft\fields\Assets) {
                $value = $element->getFieldValue($field->handle);
                $items = $value === null ? [] : (method_exists($value, 'all') ? $value->all() : []);
                foreach ($items as $item) {
                    $assetIds[$item->id] = true;
                }
                continue;
            }

            if ($navigationScanner->isNavigationField($field)) {
                $value = $element->getFieldValue($field->handle);
                if (is_string($value) && $value !== '') {
                    $menu = $navigationScanner->describeMenu($value);
                    if ($menu) {
                        $navigationMenus[$menu['handle']] = true;
                    }
                }
            }
        }
    }

    /**
     * Approximates "how many installed Pattern packages does this selection
     * touch" by checking each installed Pattern's own requires.sections
     * (Section *package* handles, not Entry Type handles - see
     * buildEntryTypeToSectionMap()) against the discovered blocks' Section
     * packages - a preview-time estimate (same spirit as
     * TemplateGeneratorService's real detectPatternReferences(), not called
     * directly since that's part of the frozen write pipeline) rather than
     * an exact reconstruction.
     *
     * @param string[] $componentEntryTypeHandles
     */
    private function countReferencedPatterns(array $componentEntryTypeHandles): int
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $entryTypeToSection = $this->buildEntryTypeToSectionMap();
        $sectionPackageHandles = array_values(array_filter(array_map(
            fn($entryTypeHandle) => $entryTypeToSection[$entryTypeHandle] ?? null,
            $componentEntryTypeHandles
        )));

        $count = 0;
        foreach ($packageManager->getAllPackages() as $pkg) {
            if (strtolower($pkg->type) !== 'pattern') {
                continue;
            }
            $manifest = $pkg->getManifest();
            $sections = (array)($manifest?->requires['sections'] ?? []);
            if (array_intersect($sections, $sectionPackageHandles)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Same technique as TemplateGeneratorService::buildEntryTypeToSectionMap()
     * (independently reimplemented, not called - that class is frozen; the
     * same duplication already used in PageUpdateService, Phase 9.2):
     * scans every installed Section package's matrix.yaml for its
     * [entryTypeHandle => sectionPackageHandle] mapping.
     *
     * @return array<string, string>
     */
    private function buildEntryTypeToSectionMap(): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $map = [];

        foreach ($packageManager->getAllPackages() as $pkg) {
            if (strtolower($pkg->type) !== 'section') {
                continue;
            }
            $path = $packageManager->getPackagePath($pkg->handle);
            if (!$path) {
                continue;
            }
            $matrixYamlPath = $path . '/matrix.yaml';
            if (!file_exists($matrixYamlPath)) {
                continue;
            }
            $matrixData = \Symfony\Component\Yaml\Yaml::parseFile($matrixYamlPath);
            $entryTypeHandle = $matrixData['blocks'][0]['handle'] ?? null;
            if ($entryTypeHandle) {
                $map[$entryTypeHandle] = $pkg->handle;
            }
        }

        return $map;
    }

    private function getMatrixFieldHandle(): ?string
    {
        $settings = Site7Studio::getInstance()->getSettings();
        if (!$settings->matrixFieldId) {
            return null;
        }
        $field = \Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
        return $field?->handle;
    }
}
