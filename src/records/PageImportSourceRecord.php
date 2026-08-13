<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * PageImportSourceRecord - one row per imported Page package, tracking the
 * live Craft Entry it was imported from (Phase 9.2). Mirrors
 * SectionImportSourceRecord; see PageImportSourceRepository for the
 * read/write API.
 */
class PageImportSourceRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%site7_page_import_sources}}';
    }
}
