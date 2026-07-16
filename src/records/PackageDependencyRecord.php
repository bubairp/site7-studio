<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * PackageDependencyRecord
 */
class PackageDependencyRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%site7_package_dependencies}}';
    }
}
