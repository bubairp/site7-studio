<?php

namespace site7\studio\repositories;

use craft\base\Component;
use site7\studio\records\SectionImportSourceRecord;

/**
 * SectionImportSourceRepository handles database interactions for
 * SectionImportSourceRecord (Phase 9.1's "Import Existing Section"
 * duplicate-detection/update-tracking). Deliberately as thin as
 * PackageRepository, and instantiated the same way (plain `new`, not a
 * registered Yii component).
 */
class SectionImportSourceRepository extends Component
{
    /**
     * Finds the source-tracking row for a live Craft resource, by its stable
     * `uid` - never its numeric id. Null means the resource has never been
     * imported as a Section package.
     */
    public function findBySourceUid(string $uid): ?SectionImportSourceRecord
    {
        return SectionImportSourceRecord::find()->where(['sourceUid' => $uid])->one();
    }

    /**
     * Finds the source-tracking row for an already-imported Section package,
     * by its PackageRecord id. Null means this package wasn't produced by
     * the Resource Importer (or predates Phase 9.1).
     */
    public function findByPackageId(int $packageId): ?SectionImportSourceRecord
    {
        return SectionImportSourceRecord::find()->where(['packageId' => $packageId])->one();
    }

    /**
     * Records (or refreshes) a Section package's import provenance. Upserts
     * on `packageId` - re-importing isn't possible (MatrixEntryTypeImportService
     * guards against it), but the Update Package workflow calls this again
     * with a fresh `sourceHash`/`importedAt` after syncing an existing package
     * in place.
     */
    public function record(int $packageId, string $sourceUid, string $sourceType, string $sourceHandle, string $sourceHash): SectionImportSourceRecord
    {
        $record = $this->findByPackageId($packageId) ?? new SectionImportSourceRecord();

        $record->packageId = $packageId;
        $record->sourceUid = $sourceUid;
        $record->sourceType = $sourceType;
        $record->sourceHandle = $sourceHandle;
        $record->sourceHash = $sourceHash;
        $record->importedAt = date('Y-m-d H:i:s');
        $record->save();

        return $record;
    }
}
