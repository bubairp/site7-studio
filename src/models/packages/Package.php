<?php

namespace site7\studio\models\packages;

use craft\base\Model;

/**
 * Abstract base class for all Site7 packages.
 */
abstract class Package extends Model
{
    /**
     * @var PackageManifest
     */
    public PackageManifest $manifest;

    /**
     * @var string Absolute path to the package directory or archive.
     */
    public string $path = '';

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['manifest', 'path'], 'required'];
        return $rules;
    }

    /**
     * Get the package type identifier (e.g., 'section', 'template').
     *
     * @return string
     */
    abstract public static function getPackageType(): string;
}
