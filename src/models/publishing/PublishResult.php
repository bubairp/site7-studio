<?php

namespace site7\studio\models\publishing;

use craft\base\Model;

/**
 * The outcome of PackagePublisherInterface::publish() - what actually
 * happened when a built .s7pkg was handed to a PackagePublishTargetInterface.
 */
class PublishResult extends Model
{
    public bool $success = false;
    public string $handle = '';
    public string $repositoryHandle = '';
    public string $version = '';
    public ?string $publishedAt = null;
    public ?string $message = null;

    /** Absolute path to the built .s7pkg that was published (or attempted). */
    public string $packagePath = '';

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['success'], 'boolean'];
        $rules[] = [['handle', 'repositoryHandle', 'version'], 'required'];
        $rules[] = [['handle', 'repositoryHandle', 'version', 'publishedAt', 'message', 'packagePath'], 'string'];
        return $rules;
    }
}
