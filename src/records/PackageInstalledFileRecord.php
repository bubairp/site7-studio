<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * PackageInstalledFileRecord - one row per (package, target file) pair, see
 * migration m260817_150000_create_installed_files_table for the schema and
 * why it exists.
 */
class PackageInstalledFileRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%site7_installed_files}}';
    }
}
