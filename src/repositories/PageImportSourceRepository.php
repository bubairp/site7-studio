<?php

namespace site7\studio\repositories;

use craft\base\Component;
use site7\studio\records\PageImportSourceRecord;

/**
 * PageImportSourceRepository handles database interactions for
 * PageImportSourceRecord (Phase 9.2's "Import Existing Page"
 * duplicate-detection/update-tracking). Mirrors SectionImportSourceRepository
 * exactly - plain `new`-instantiated component, not a registered Yii
 * component.
 */
class PageImportSourceRepository extends Component
{
    /**
     * Finds the source-tracking row for a live Craft Entry, by its stable
     * `uid` - never its numeric id. Null means the Entry has never been
     * imported as a Page package.
     */
    public function findBySourceUid(string $uid): ?PageImportSourceRecord
    {
        return PageImportSourceRecord::find()->where(['sourceUid' => $uid])->one();
    }

    /**
     * Finds the source-tracking row for an already-imported Page package, by
     * its PackageRecord id. Null means this package wasn't produced by the
     * Resource Importer's Page path (or predates Phase 9.2).
     */
    public function findByPackageId(int $packageId): ?PageImportSourceRecord
    {
        return PageImportSourceRecord::find()->where(['packageId' => $packageId])->one();
    }

    /**
     * Records (or refreshes) a Page package's import provenance. Upserts on
     * `packageId` - re-importing isn't possible (PageImportService guards
     * against it), but the Update Package workflow calls this again with a
     * fresh `sourceHash`/`importedAt` after syncing an existing package in
     * place.
     */
    public function record(int $packageId, string $sourceUid, string $sourceHandle, string $sourceHash): PageImportSourceRecord
    {
        $record = $this->findByPackageId($packageId) ?? new PageImportSourceRecord();

        $record->packageId = $packageId;
        $record->sourceUid = $sourceUid;
        $record->sourceHandle = $sourceHandle;
        $record->sourceHash = $sourceHash;
        $record->importedAt = date('Y-m-d H:i:s');
        $record->save();

        return $record;
    }
}
