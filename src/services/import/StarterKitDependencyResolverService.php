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
     * @return array{sections: int, pages: int, categories: int, assets: int, components: int, globals: int, navigation: int, templates: int, composerPackages: int, npmPackages: int, plugins: int}
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
