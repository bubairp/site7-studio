<?php

namespace site7\studio\models;

use craft\base\Model;

/**
 * Site7 Studio Settings Model
 */
class Settings extends Model
{
    public ?int $matrixFieldId = null;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['matrixFieldId'], 'integer'];
        return $rules;
    }
}
