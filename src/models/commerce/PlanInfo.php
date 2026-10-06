<?php

namespace site7\studio\models\commerce;

use craft\base\Model;

/**
 * A plan definition as reported by Commerce24 (Starter/Professional/Business/
 * Enterprise or any future plan Commerce24 introduces). Nothing in
 * SITE7 Studio hardcodes plan names or their entitlements - FeatureGateService
 * reads a PlanInfo's $features array instead. See FeatureGateInterface.
 */
class PlanInfo extends Model
{
    public string $handle = '';
    public string $name = '';

    /** @var string[] Feature flag handles this plan grants (e.g. 'starterKits', 'marketplace'). */
    public array $features = [];

    /** @var string[] Package handles included with this plan at no extra cost. */
    public array $includedPackages = [];

    public ?int $websiteLimit = null;
    public ?int $userLimit = null;
    public ?int $storageLimit = null;
    /** Packages a site may install beyond its Starter Kit (the kit's own don't count); null = unlimited. */
    public ?int $packageLimit = null;
    public bool $apiAccess = false;
    public bool $marketplaceAccess = false;
    public ?string $supportLevel = null;
    public ?string $updateChannel = null;

    /**
     * Keys Commerce24 adds later are ignored, so an older plugin keeps
     * reading plans instead of failing on an unknown property.
     */
    public function __construct($config = [])
    {
        parent::__construct(array_intersect_key($config, array_flip(array_keys(get_class_vars(static::class)))));
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['handle', 'name'], 'required'];
        $rules[] = [['handle', 'name', 'supportLevel', 'updateChannel'], 'string'];
        $rules[] = [['features', 'includedPackages'], 'safe'];
        $rules[] = [['websiteLimit', 'userLimit', 'storageLimit', 'packageLimit'], 'integer'];
        $rules[] = [['apiAccess', 'marketplaceAccess'], 'boolean'];
        return $rules;
    }

    public function grants(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }
}
