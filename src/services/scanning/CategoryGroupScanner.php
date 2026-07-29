<?php

namespace site7\studio\services\scanning;

use Craft;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use site7\studio\interfaces\ResourceScannerInterface;

/**
 * Discovers native Craft Category Groups - distinct from a Craft Section
 * that merely happens to have a category-like name (see the Phase 16
 * architecture doc's example: blogCategories/portfolioCategories Sections
 * are ordinary content, not true Category Groups).
 */
class CategoryGroupScanner implements ResourceScannerInterface
{
    /** @return CategoryGroup[] */
    public function scan(): array
    {
        return Craft::$app->getCategories()->getAllGroups();
    }

    public function findByHandle(string $handle): ?CategoryGroup
    {
        return Craft::$app->getCategories()->getGroupByHandle($handle);
    }

    public function findByUid(string $uid): ?CategoryGroup
    {
        return Craft::$app->getCategories()->getGroupByUid($uid);
    }

    /** @return CategoryGroup_SiteSettings[] */
    public function siteSettingsFor(CategoryGroup $group): array
    {
        return $group->getSiteSettings();
    }
}
