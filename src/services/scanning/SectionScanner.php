<?php

namespace site7\studio\services\scanning;

use Craft;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use site7\studio\interfaces\ResourceScannerInterface;

/**
 * Discovers native Craft Sections (config/project/sections/*) - never a
 * Site7 "Section" package, which wraps a single Entry Type instead. See the
 * Phase 16 architecture doc's terminology note for why this distinction is
 * called out explicitly throughout this codebase.
 */
class SectionScanner implements ResourceScannerInterface
{
    /** @return Section[] */
    public function scan(): array
    {
        return Craft::$app->getEntries()->getAllSections();
    }

    public function findByHandle(string $handle): ?Section
    {
        return Craft::$app->getEntries()->getSectionByHandle($handle);
    }

    public function findByUid(string $uid): ?Section
    {
        return Craft::$app->getEntries()->getSectionByUid($uid);
    }

    /** @return Section_SiteSettings[] */
    public function siteSettingsFor(Section $section): array
    {
        return $section->getSiteSettings();
    }
}
