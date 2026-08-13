<?php

namespace site7\studio\repositories;

use craft\base\Component;
use site7\studio\records\WebsiteImportSourceRecord;

/**
 * WebsiteImportSourceRepository handles database interactions for
 * WebsiteImportSourceRecord (Phase 9.3's "Import Existing Website"
 * duplicate-detection/update-tracking). Mirrors SectionImportSourceRepository/
 * PageImportSourceRepository - plain `new`-instantiated component, not a
 * registered Yii component - except identity is a `selectionKey` (a website
 * has no single source uid) rather than a single uid column.
 */
class WebsiteImportSourceRepository extends Component
{
    /**
     * Computes the deterministic identity of a page selection - a website
     * has no single source uid, so identity is the sha256 of the sorted,
     * deduplicated list of captured Entry uids. Order-independent: the same
     * set of pages selected in any order resolves to the same key.
     *
     * @param string[] $entryUids
     */
    public function computeSelectionKey(array $entryUids): string
    {
        $unique = array_values(array_unique($entryUids));
        sort($unique);
        return hash('sha256', implode(',', $unique));
    }

    /**
     * Finds the source-tracking row for a given page selection, by its
     * `selectionKey`. Null means this exact selection has never been
     * imported as a Starter Kit.
     */
    public function findBySelectionKey(string $selectionKey): ?WebsiteImportSourceRecord
    {
        return WebsiteImportSourceRecord::find()->where(['selectionKey' => $selectionKey])->one();
    }

    /**
     * Finds the source-tracking row for an already-imported Starter Kit
     * package, by its PackageRecord id. Null means this package wasn't
     * produced by the Resource Importer's Website path (or predates Phase
     * 9.3).
     */
    public function findByPackageId(int $packageId): ?WebsiteImportSourceRecord
    {
        return WebsiteImportSourceRecord::find()->where(['packageId' => $packageId])->one();
    }

    /**
     * Records (or refreshes) a Starter Kit package's import provenance.
     * Upserts on `packageId` - re-importing the exact same selection isn't
     * possible (WebsiteImportService guards against it), but Synchronize
     * Starter Kit calls this again with a fresh `sourceHash`/`importedAt`
     * after syncing an existing package in place.
     *
     * @param string[] $entryUids
     */
    public function record(int $packageId, array $entryUids, string $sourceHash): WebsiteImportSourceRecord
    {
        $record = $this->findByPackageId($packageId) ?? new WebsiteImportSourceRecord();

        $record->packageId = $packageId;
        $record->selectionKey = $this->computeSelectionKey($entryUids);
        $record->sourceEntryUids = json_encode(array_values($entryUids), JSON_UNESCAPED_SLASHES);
        $record->sourceHash = $sourceHash;
        $record->importedAt = date('Y-m-d H:i:s');
        $record->save();

        return $record;
    }
}
