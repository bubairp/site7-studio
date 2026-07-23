<?php

namespace site7\studio\models\commerce;

use craft\base\Model;

/**
 * The current site's license, as reported by Commerce24. Never constructed
 * with hardcoded/sample data outside of tests - always the (possibly cached)
 * result of a real CommerceClient call, so a disconnected/unlicensed
 * installation is representable as a model with $status = 'unlicensed'
 * rather than by the absence of one.
 */
class LicenseInfo extends Model
{
    public ?string $key = null;
    public string $status = 'unlicensed';
    public ?string $activationDate = null;
    public ?string $expiryDate = null;

    /** @var string[] Domains currently activated against this license. */
    public array $activatedDomains = [];

    public ?string $machineId = null;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['status'], 'required'];
        $rules[] = [['key', 'status', 'activationDate', 'expiryDate', 'machineId'], 'string'];
        $rules[] = [['activatedDomains'], 'safe'];
        return $rules;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
