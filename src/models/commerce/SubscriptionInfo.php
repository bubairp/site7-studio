<?php

namespace site7\studio\models\commerce;

use craft\base\Model;

/**
 * The current site's subscription state, as reported by Commerce24.
 */
class SubscriptionInfo extends Model
{
    public ?string $planHandle = null;
    public ?string $planName = null;
    public string $status = 'none';
    public ?string $renewalDate = null;
    public ?string $billingCycle = null;
    public ?string $manageUrl = null;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['status'], 'required'];
        $rules[] = [['planHandle', 'planName', 'status', 'renewalDate', 'billingCycle', 'manageUrl'], 'string'];
        return $rules;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
