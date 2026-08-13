<?php

namespace site7\studio\services\import;

use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Tags;
use site7\studio\models\import\PageDependencyPreview;
use site7\studio\records\PackageRecord;
use site7\studio\repositories\SectionImportSourceRepository;
use site7\studio\services\scanning\NavigationScanner;
use site7\studio\Site7Studio;

/**
 * Phase 9.2's "Smart Dependency Import" - given a live Entry, computes
 * everything the Dependency Preview shows before the user imports it. Pure
 * read/compute, same rationale as CraftResourceDiscoveryService (Phase 9.1):
 * feeds a smarter Preview step, never touches the existing Analyze/Preview/
 * Save write pipeline (PageImportService/TemplateGeneratorService are
 * untouched by this service).
 *
 * Deliberately narrower than the full "8 dependencies" example in the phase
 * brief: it reports what's structurally resolvable from one live Entry
 * (matrix block Entry Types, actually-selected Category/Tag/Asset relations,
 * real Navigation menus) - Patterns/Templates are a SITE7 authoring concept
 * with no live-Craft equivalent to detect, and Global Sets have no
 * structural link from a single Entry (see PageDependencyPreview::$globals).
 */
class PageDependencyResolverService
{
    public function resolve(Entry $entry): PageDependencyPreview
    {
        $preview = new PageDependencyPreview();

        $section = $entry->getSection();
        $entryType = $entry->getType();
        $preview->sectionHandle = $section?->handle ?? '';
        $preview->sectionName = $section?->name ?? '';
        $preview->entryTypeHandle = $entryType->handle;
        $preview->entryTypeName = $entryType->name;

        $sourceRepo = new SectionImportSourceRepository();
        $navigationScanner = new NavigationScanner();

        $matrixHandle = $this->getMatrixFieldHandle();
        $relationElements = [];

        // The page's own native fields - real Category/Tag/Asset selections
        // and any Navigation field, same signal WebsiteImportService reads
        // per-page.
        $this->collectFieldDependencies($entry, $navigationScanner, $preview, $relationElements, $matrixHandle ? [$matrixHandle] : []);

        // Site7 Matrix content, if any - each block's Entry Type is checked
        // against Phase 9.1's Section-package tracking (available/missing),
        // and each block's own fields are walked the same way as the page's.
        if ($matrixHandle && $entry->getFieldLayout()?->getFieldByHandle($matrixHandle)) {
            $fieldValue = $entry->getFieldValue($matrixHandle);
            $blocks = $fieldValue ? $fieldValue->status(null)->drafts(null)->savedDraftsOnly(false)->all() : [];
            foreach ($blocks as $block) {
                $blockType = $block->getType();
                $sourceRecord = $sourceRepo->findBySourceUid($blockType->uid);
                $packageHandle = null;
                if ($sourceRecord) {
                    $package = PackageRecord::findOne($sourceRecord->packageId);
                    $packageHandle = $package?->handle;
                }
                $preview->matrixBlocks[] = [
                    'entryTypeHandle' => $blockType->handle,
                    'name' => $blockType->name,
                    'packageStatus' => $sourceRecord ? 'available' : 'missing',
                    'packageHandle' => $packageHandle,
                ];

                $this->collectFieldDependencies($block, $navigationScanner, $preview, $relationElements, []);
            }
        }

        $preview->pageCount = 1;

        return $preview;
    }

    /**
     * Walks one element's (the page itself, or one matrix block) own field
     * layout for Category/Tag/Asset relation values and Navigation fields,
     * appending real, deduplicated results onto $preview.
     *
     * @param array<string, true> $seenElements by-reference dedup guard (element class . ':' . id), so the same Asset/Category referenced by two different fields/blocks is only listed once.
     */
    private function collectFieldDependencies(Entry $element, NavigationScanner $navigationScanner, PageDependencyPreview $preview, array &$seenElements, array $skipHandles): void
    {
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (in_array($field->handle, $skipHandles, true)) {
                continue;
            }

            if ($field instanceof Categories || $field instanceof Tags) {
                foreach ($this->resolveRelationValues($element, $field) as $term) {
                    if (!$term instanceof Category) {
                        continue;
                    }
                    $key = 'category:' . $term->id;
                    if (isset($seenElements[$key])) {
                        continue;
                    }
                    $seenElements[$key] = true;
                    $preview->categories[] = [
                        'id' => $term->id,
                        'title' => $term->title,
                        'groupName' => $term->getGroup()->name,
                    ];
                }
                continue;
            }

            if ($field instanceof Assets) {
                foreach ($this->resolveRelationValues($element, $field) as $asset) {
                    if (!$asset instanceof Asset) {
                        continue;
                    }
                    $key = 'asset:' . $asset->id;
                    if (isset($seenElements[$key])) {
                        continue;
                    }
                    $seenElements[$key] = true;
                    $preview->assets[] = [
                        'id' => $asset->id,
                        'filename' => $asset->filename,
                    ];
                }
                continue;
            }

            if ($navigationScanner->isNavigationField($field)) {
                $value = $element->getFieldValue($field->handle);
                if (is_string($value) && $value !== '') {
                    $menu = $navigationScanner->describeMenu($value);
                    if ($menu) {
                        $key = 'nav:' . $menu['handle'];
                        if (!isset($seenElements[$key])) {
                            $seenElements[$key] = true;
                            $preview->navigation[] = ['fieldHandle' => $field->handle, 'menuName' => $menu['name']];
                        }
                    }
                }
            }
        }
    }

    /** @return array<int, \craft\base\ElementInterface> */
    private function resolveRelationValues(Entry $element, FieldInterface $field): array
    {
        $value = $element->getFieldValue($field->handle);
        if ($value === null) {
            return [];
        }
        // Relation field values are element queries - ->all() executes it;
        // a raw array (already-resolved value) is passed through as-is.
        return is_array($value) ? $value : (method_exists($value, 'all') ? $value->all() : []);
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
