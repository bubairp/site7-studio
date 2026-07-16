<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * TemplateRecord
 */
class TemplateRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%site7_templates}}';
    }
}
