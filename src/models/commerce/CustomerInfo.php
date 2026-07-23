<?php

namespace site7\studio\models\commerce;

use craft\base\Model;

/**
 * The Commerce24 customer/account record tied to this site's license.
 */
class CustomerInfo extends Model
{
    public ?string $id = null;
    public ?string $name = null;
    public ?string $email = null;
    public ?string $company = null;
    public ?string $organization = null;
    public ?string $portalUrl = null;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['id', 'name', 'email', 'company', 'organization', 'portalUrl'], 'string'];
        return $rules;
    }
}
