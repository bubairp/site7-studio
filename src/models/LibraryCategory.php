<?php

namespace site7\studio\models;

use craft\base\Model;

/**
 * LibraryCategory
 *
 * Represents a category grouping in the Site7 Library sidebar.
 */
class LibraryCategory extends Model
{
    public string $handle = '';
    public string $label = '';
    public ?string $icon = null;
    public int $sortOrder = 0;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['handle', 'label'], 'required'];
        $rules[] = [['handle', 'label', 'icon'], 'string'];
        $rules[] = [['sortOrder'], 'integer'];
        return $rules;
    }
}
