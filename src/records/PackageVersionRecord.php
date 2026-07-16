<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * PackageVersionRecord
 */
class PackageVersionRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%site7_package_versions}}';
    }
}
