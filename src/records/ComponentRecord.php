<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * ComponentRecord
 */
class ComponentRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%site7_components}}';
    }
}
