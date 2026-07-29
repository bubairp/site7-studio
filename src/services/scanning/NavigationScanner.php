<?php

namespace site7\studio\services\scanning;

use craft\base\FieldInterface;
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
}
