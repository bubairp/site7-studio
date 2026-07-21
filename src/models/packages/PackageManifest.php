<?php

namespace site7\studio\models\packages;

use craft\base\Model;

/**
 * Represents the data defined inside a package's manifest.json.
 */
class PackageManifest extends Model
{
    public string $schemaVersion = '1';
    public string $type = 'section';
    public string $handle = '';
    public string $name = '';
    public string $version = '1.0.0';
    public string $author = '';
    public string $description = '';
    public ?string $category = null;
    public array $tags = [];
    public array $compatibility = [];
    public array $dependencies = [];
    public array $requires = [];
    public array $demoContent = [];
    public ?string $preview = null;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['type', 'handle', 'name', 'version', 'schemaVersion'], 'required'];
        $rules[] = [['type', 'handle', 'name', 'version', 'schemaVersion', 'author', 'description', 'category', 'preview'], 'string'];
        $rules[] = [['compatibility', 'dependencies', 'tags', 'requires', 'demoContent'], 'safe'];
        return $rules;
    }
}
