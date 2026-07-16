<?php

namespace site7\studio\models;

use craft\base\Model;

/**
 * TemplateAsset
 *
 * Represents a full page layout or starter kit in the Site7 Library.
 */
class TemplateAsset extends Asset
{
    public array $supportedPlans = [];
    public ?string $homepage = null;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['handle', 'name', 'sourceHandle'], 'required'];
        $rules[] = [['handle', 'name', 'sourceHandle'], 'string'];
        $rules[] = [['supportedPlans'], 'safe'];
        return $rules;
    }
}
