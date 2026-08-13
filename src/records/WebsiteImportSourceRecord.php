<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * WebsiteImportSourceRecord - one row per imported Starter Kit package,
 * tracking the live Craft website selection it was captured from (Phase
 * 9.3). Mirrors SectionImportSourceRecord/PageImportSourceRecord; see
 * WebsiteImportSourceRepository for the read/write API.
 */
class WebsiteImportSourceRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%site7_website_import_sources}}';
    }
}
