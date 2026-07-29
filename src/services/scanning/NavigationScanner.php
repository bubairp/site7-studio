<?php

namespace site7\studio\services\scanning;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use site7\studio\interfaces\ResourceScannerInterface;

/**
 * Discovers "Navigation" - which has no native Craft CMS concept of its own,
 * so this scanner only recognizes fields provided by a known navigation
 * plugin (currently remoteprogrammer/simple-rp-menu, per the Phase 16
 * architecture doc's finding that some projects have a real plugin-backed
 * nav system rather than needing the Structure-nesting approximation).
 * Projects without such a plugin installed simply scan to an empty array -
 * callers fall back to the existing Structure-nesting approximation
 * themselves (see WebsiteImportService's pages[].parentSlug), which isn't a
 * "resource" this scanner can discover.
 *
 * The prefix check below is the single source of truth for this detection;
 * it replaces the copy that used to live only inside
 * CraftResourceDiscoveryService.
 */
class NavigationScanner implements ResourceScannerInterface
{
    private const NAVIGATION_PLUGIN_FIELD_PREFIXES = [
        'remoteprogrammer\\simplerpmenu',
    ];

    /** The remoteprogrammer/simple-rp-menu plugin's registered Craft plugin handle. */
    private const SIMPLE_RP_MENU_PLUGIN_HANDLE = 'simple-rp-menu';

    /** @return FieldInterface[] Fields provided by a recognized navigation plugin, project-wide. */
    public function scan(): array
    {
        $fieldScanner = new FieldScanner();
        return array_values(array_filter($fieldScanner->scan(), [$this, 'isNavigationField']));
    }

    public function isNavigationField(FieldInterface $field): bool
    {
        return self::classNameMatchesNavigationPrefix(get_class($field));
    }

    /**
     * The pure prefix-matching logic, extracted so it's unit-testable
     * without constructing a live Craft field object.
     */
    public static function classNameMatchesNavigationPrefix(string $fieldClass): bool
    {
        foreach (self::NAVIGATION_PLUGIN_FIELD_PREFIXES as $prefix) {
            if (str_starts_with($fieldClass, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolves a Simple RP Menu field's selected value (the menu's own
     * `handle`, per RpMenuField::getInputHtml() - the field stores nothing
     * but that string) into the real menu structure: {handle, name, items:
     * [{name, order, parentOrder, entryRef, customUrl, target, isMegaMenu}]}
     * - the actual Navigation capture Phase 3 replaces the Structure-nesting
     * approximation with, wherever this plugin is present.
     *
     * Deliberately has no `use remoteprogrammer\simplerpmenu\...` import
     * anywhere in this class - the plugin is a per-project dependency of
     * whatever site is being imported, not of site7-studio itself, so every
     * touchpoint is defensive (class_exists()/dynamic component access) and
     * returns null rather than fataling when the plugin isn't installed.
     *
     * An item's linked Entry (`entry_id` in the plugin's own schema) is
     * resolved to {sectionHandle, slug} rather than kept as a raw element
     * ID, matching this codebase's existing convention (see
     * PackageManifest::$sourceEntryType/$sourceSection's docblock) that a
     * captured reference must be portable structural identity, never a
     * runtime ID that won't resolve to anything on a fresh target site.
     *
     * @return array{handle: string, name: string, items: array}|null null if the plugin isn't installed, or no menu with this handle exists.
     */
    public function describeMenu(string $menuHandle): ?array
    {
        if ($menuHandle === '' || !class_exists('remoteprogrammer\\simplerpmenu\\SimpleRpMenu')) {
            return null;
        }

        $plugin = Craft::$app->getPlugins()->getPlugin(self::SIMPLE_RP_MENU_PLUGIN_HANDLE);
        if (!$plugin) {
            return null;
        }

        $menuService = $plugin->get('simplerpmenu');
        $itemsService = $plugin->get('simplerpmenuItems');
        if (!$menuService || !$itemsService) {
            return null;
        }

        $menuRecord = $menuService->getMenuByHandle($menuHandle);
        if (!$menuRecord) {
            return null;
        }

        $items = $itemsService->getMenuItems($menuRecord->id);

        return [
            'handle' => $menuRecord->handle,
            'name' => $menuRecord->name,
            'items' => array_map(fn(array $item) => [
                'name' => $item['name'],
                'order' => (int)$item['item_order'],
                'parentOrder' => $item['parent_id'] ? (int)$item['parent_id'] : null,
                'entryRef' => $this->resolveEntryRef($item['entry_id'] ?? null),
                'customUrl' => $item['custom_url'] ?: null,
                'target' => $item['target'] ?: null,
                'isMegaMenu' => (bool)($item['isMegaMenu'] ?? false),
            ], $items),
        ];
    }

    /**
     * @return array{sectionHandle: string, slug: ?string}|null
     */
    private function resolveEntryRef(int|string|null $entryId): ?array
    {
        if (!$entryId) {
            return null;
        }
        $entry = Entry::find()->id((int)$entryId)->status(null)->one();
        if (!$entry instanceof Entry) {
            return null;
        }
        $section = $entry->getSection();
        if (!$section) {
            return null;
        }
        return ['sectionHandle' => $section->handle, 'slug' => $entry->slug];
    }
}
