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
    public array $compatibility = [];
    public array $dependencies = [];

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['type', 'handle', 'name', 'version', 'schemaVersion'], 'required'];
        $rules[] = [['type', 'handle', 'name', 'version', 'schemaVersion', 'author', 'description'], 'string'];
        $rules[] = [['compatibility', 'dependencies'], 'safe'];
        return $rules;
    }
}
