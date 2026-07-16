<?php

namespace site7\studio\models;

use craft\base\Model;

/**
 * ComponentAsset
 *
 * Represents a reusable building block in the Site7 Library.
 */
class ComponentAsset extends Asset
{
    // Specific properties for components can be added here in the future
    
    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['handle', 'name', 'sourceHandle'], 'required'];
        $rules[] = [['handle', 'name', 'sourceHandle'], 'string'];
        $rules[] = [['tags'], 'safe'];
        return $rules;
    }
}
