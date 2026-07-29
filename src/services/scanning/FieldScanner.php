<?php

namespace site7\studio\services\scanning;

use Craft;
use craft\base\FieldInterface;
use site7\studio\interfaces\ResourceScannerInterface;

/**
 * Discovers every native Craft field defined on the project, regardless of
 * type - the general-purpose lookup that MatrixFieldScanner/NavigationScanner
 * narrow down for their own resource kind.
 */
class FieldScanner implements ResourceScannerInterface
{
    /** @return FieldInterface[] */
    public function scan(): array
    {
        return Craft::$app->getFields()->getAllFields();
    }

    public function findByHandle(string $handle): ?FieldInterface
    {
        return Craft::$app->getFields()->getFieldByHandle($handle);
    }

    public function findById(int $fieldId): ?FieldInterface
    {
        return Craft::$app->getFields()->getFieldById($fieldId);
    }
}
