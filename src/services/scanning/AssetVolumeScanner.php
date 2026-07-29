<?php

namespace site7\studio\services\scanning;

use Craft;
use craft\models\Volume;
use site7\studio\interfaces\ResourceScannerInterface;

/**
 * Discovers native Craft Asset Volumes. Website Starter Kit System Phase 2's
 * first consumer of this scanner: volumes referenced by a captured Assets
 * field are resolved here rather than the import service re-deriving the
 * 'volume:{uid}' source-string convention itself.
 */
class AssetVolumeScanner implements ResourceScannerInterface
{
    /** @return Volume[] */
    public function scan(): array
    {
        return Craft::$app->getVolumes()->getAllVolumes();
    }

    public function findByHandle(string $handle): ?Volume
    {
        return Craft::$app->getVolumes()->getVolumeByHandle($handle);
    }

    public function findByUid(string $uid): ?Volume
    {
        return Craft::$app->getVolumes()->getVolumeByUid($uid);
    }
}
