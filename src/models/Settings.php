<?php

namespace site7\studio\models;

use craft\base\Model;

/**
 * Site7 Studio Settings Model
 */
class Settings extends Model
{
    public ?int $matrixFieldId = null;

    /**
     * The package to fall back to when a user has no active subscription
     * (referenced by src/console/controllers/PackageController.php's sync
     * command). Never a hardcoded package - set per-install on the Commerce
     * tab of Settings.
     */
    public ?string $defaultPackage = null;

    // --- General (defaults applied to newly-authored packages, pre-filling
    // the New Package wizard - see PackageAuthoringService::createPackage()) ---

    public ?string $defaultPackageAuthor = null;

    public string $defaultPackageLicense = 'MIT';

    public string $defaultPackageVersion = '1.0.0';

    /**
     * The Category dropdown's options on the New Package wizard and the
     * Publish wizard's Metadata step - an admin-managed list rather than
     * free text, so Library's Category filter (which matches these values
     * exactly) doesn't fragment on typos/casing ("media" vs "Media").
     *
     * Seeded from the Base Project's actual component set (accordion,
     * blogET, productET, etc.) grouped by what they represent, not a
     * generic placeholder list - see docs/PHASE-16-FEATURE-PACKAGE-ARCHITECTURE.md
     * for that inventory.
     *
     * @var string[]
     */
    public array $packageCategories = [
        'Header',
        'About',
        'Business',
        'Marketing',
        'Media',
        'Blog',
        'Portfolio',
        'Products',
        'Team',
        'Forms',
        'Content',
    ];

    // --- Commerce & Licensing (Commerce24 integration) ---

    /** Base URL of the Commerce24 API this site talks to. */
    public ?string $commerceApiEndpoint = null;

    /** Identifies which Commerce24 store/tenant this installation belongs to. */
    public ?string $commerceStoreIdentifier = null;

    /** 'production', 'staging', or any environment Commerce24 recognizes. */
    public string $commerceEnvironment = 'production';

    /**
     * The Commerce24 API key. Supports Craft's `$ENV_VAR_NAME` syntax
     * (resolved via craft\helpers\App::parseEnv() in CommerceClient) so the
     * real value never has to live in the database/project config.
     */
    public ?string $commerceApiKey = null;

    public bool $commerceDebugMode = false;

    /** Seconds a Commerce24 GET response is cached for before being re-fetched. */
    public int $commerceCacheDuration = 300;

    /** Seconds before a Commerce24 request times out. */
    public int $commerceTimeout = 10;

    /**
     * Feature handles FeatureGateService still allows when no plan can be
     * resolved (Commerce24 unconfigured/unreachable). Empty by default -
     * FeatureGateService fails closed.
     *
     * @var string[]
     */
    public array $commerceOfflineFeatures = [];

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['matrixFieldId'], 'integer'];
        $rules[] = [['defaultPackage', 'commerceApiEndpoint', 'commerceApiKey', 'commerceStoreIdentifier', 'commerceEnvironment'], 'string'];
        $rules[] = [
            ['defaultPackageAuthor', 'defaultPackageLicense', 'defaultPackageVersion'],
            'string',
        ];
        $rules[] = [['defaultPackageLicense'], 'default', 'value' => 'MIT'];
        $rules[] = [['defaultPackageVersion'], 'default', 'value' => '1.0.0'];
        $rules[] = [['commerceEnvironment'], 'default', 'value' => 'production'];
        $rules[] = [['commerceDebugMode'], 'boolean'];
        $rules[] = [['commerceCacheDuration', 'commerceTimeout'], 'integer'];
        $rules[] = [['commerceCacheDuration'], 'default', 'value' => 300];
        $rules[] = [['commerceTimeout'], 'default', 'value' => 10];
        $rules[] = [['commerceOfflineFeatures'], 'safe'];
        $rules[] = [['packageCategories'], 'safe'];
        return $rules;
    }
}
