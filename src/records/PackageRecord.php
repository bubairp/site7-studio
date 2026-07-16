<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * PackageRecord
 */
class PackageRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%site7_packages}}';
    }
}
