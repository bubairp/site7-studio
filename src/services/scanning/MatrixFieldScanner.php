<?php

namespace site7\studio\services\scanning;

use Craft;
use craft\fields\Matrix;
use site7\studio\interfaces\ResourceScannerInterface;

/**
 * Discovers every native Craft Matrix field project-wide, including but not
 * limited to this plugin's own content Matrix (site7Components) - the
 * project may have other, unrelated Matrix fields (e.g. a "matrixContent"
 * field), and callers browsing/filtering by Matrix field must see all of
 * them, not just the Site7 one.
 */
class MatrixFieldScanner implements ResourceScannerInterface
{
    /** @return Matrix[] */
    public function scan(): array
    {
        $matrixFields = [];
        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if ($field instanceof Matrix) {
                $matrixFields[] = $field;
            }
        }
        return $matrixFields;
    }

    /**
     * Every Matrix field project-wide, mapped to the Entry Type handles it
     * allows as block types - the "used as a block on N Matrix fields"
     * fan-out map several consumers (shared-resource detection, the Select
     * step's usage count) need without re-deriving it themselves.
     *
     * @return array<string, string[]> entryTypeHandle => [Matrix field handles]
     */
    public function entryTypeUsageMap(): array
    {
        $map = [];
        foreach ($this->scan() as $field) {
            foreach ($field->getEntryTypes() as $entryType) {
                $map[$entryType->handle][] = $field->handle;
            }
        }
        return $map;
    }
}
