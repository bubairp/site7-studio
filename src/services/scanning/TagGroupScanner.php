<?php

namespace site7\studio\services\scanning;

use Craft;
use craft\models\TagGroup;
use site7\studio\interfaces\ResourceScannerInterface;

/**
 * Discovers native Craft Tag Groups.
 */
class TagGroupScanner implements ResourceScannerInterface
{
    /** @return TagGroup[] */
    public function scan(): array
    {
        return Craft::$app->getTags()->getAllTagGroups();
    }

    public function findByHandle(string $handle): ?TagGroup
    {
        return Craft::$app->getTags()->getTagGroupByHandle($handle);
    }

    public function findByUid(string $uid): ?TagGroup
    {
        return Craft::$app->getTags()->getTagGroupByUid($uid);
    }
}
