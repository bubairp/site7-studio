<?php

namespace site7\studio\models;

use craft\base\Model;

/**
 * Class Asset
 *
 * A generalized base model for items in the Site7 Library.
 * Can represent Components, Templates, Patterns, or Starter Kits.
 */
class Asset extends Model
{
    public ?string $id = null;
    public ?string $packageId = null;
    public string $handle = '';
    public string $name = '';
    public ?string $description = null;
    public ?string $category = null;
    public ?string $icon = null;
    public ?string $previewImage = null;
    public array $tags = [];
    public ?string $requiredPlan = null;
    public string $sourceHandle = '';

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['handle', 'name', 'sourceHandle'], 'required'];
        $rules[] = [['handle', 'name', 'sourceHandle', 'description'], 'string'];
        $rules[] = [['tags'], 'safe'];
        return $rules;
    }
}
