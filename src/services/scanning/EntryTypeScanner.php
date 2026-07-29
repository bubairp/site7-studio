<?php

namespace site7\studio\services\scanning;

use Craft;
use craft\models\EntryType;
use site7\studio\interfaces\ResourceScannerInterface;

/**
 * Discovers native Craft Entry Types project-wide - both the ones that back
 * a real Craft Section and the ones used purely as Matrix field block types
 * (including this plugin's own Site7 Sections). Callers needing to tell
 * those apart already have dedicated classification logic
 * (CraftResourceDiscoveryService, ResourceClassifierService) - this scanner
 * only enumerates/looks up, it never classifies.
 */
class EntryTypeScanner implements ResourceScannerInterface
{
    /** @return EntryType[] */
    public function scan(): array
    {
        return Craft::$app->getEntries()->getAllEntryTypes();
    }

    public function findById(int $entryTypeId): ?EntryType
    {
        return Craft::$app->getEntries()->getEntryTypeById($entryTypeId);
    }

    public function findByHandle(string $handle): ?EntryType
    {
        return Craft::$app->getEntries()->getEntryTypeByHandle($handle);
    }

    /** @return EntryType[] */
    public function forSection(int $sectionId): array
    {
        return Craft::$app->getEntries()->getEntryTypesBySectionId($sectionId);
    }
}
