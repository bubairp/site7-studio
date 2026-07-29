<?php

namespace site7\studio\services\scanning;

use Craft;
use craft\elements\GlobalSet;
use site7\studio\interfaces\ResourceScannerInterface;

/**
 * Discovers native Craft Global Sets.
 */
class GlobalSetScanner implements ResourceScannerInterface
{
    /** @return GlobalSet[] */
    public function scan(): array
    {
        return Craft::$app->getGlobals()->getAllSets();
    }

    public function findById(int $globalSetId): ?GlobalSet
    {
        return Craft::$app->getGlobals()->getSetById($globalSetId);
    }

    public function findByHandle(string $handle): ?GlobalSet
    {
        return Craft::$app->getGlobals()->getSetByHandle($handle);
    }
}
