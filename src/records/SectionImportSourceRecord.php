<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * SectionImportSourceRecord - one row per imported Section package, tracking
 * the live Craft Entry Type it was imported from (Phase 9.1). See
 * SectionImportSourceRepository for the read/write API; this class is
 * intentionally as thin as PackageRecord.
 */
class SectionImportSourceRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%site7_section_import_sources}}';
    }
}
