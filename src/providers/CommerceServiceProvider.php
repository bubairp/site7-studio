<?php

namespace site7\studio\providers;

use site7\studio\services\commerce\CommerceClient;
use site7\studio\services\commerce\DownloadService;
use site7\studio\services\commerce\FeatureGateService;
use site7\studio\services\commerce\LicenseService;
use site7\studio\services\commerce\PackageService;
use site7\studio\services\commerce\PlanService;
use site7\studio\services\commerce\SubscriptionService;
use site7\studio\services\commerce\UpdateService;
use site7\studio\Site7Studio;

/**
 * Registers the Commerce & Licensing Platform's services onto the plugin's
 * service locator, following the same ServiceProviderInterface pattern as
 * CoreServiceProvider/CpServiceProvider/LibraryServiceProvider.
 *
 * `commercePackages` is deliberately not named `packageManager` - that
 * remains the Package Engine's own service (install/enable/disable state on
 * disk); this one is Commerce24's purchase/entitlement view layered on top
 * of it. See PackageService's docblock.
 */
class CommerceServiceProvider implements ServiceProviderInterface
{
    /**
     * @inheritdoc
     */
    public function register(Site7Studio $plugin): void
    {
        $plugin->set('commerceClient', ['class' => CommerceClient::class]);
        $plugin->set('license', ['class' => LicenseService::class]);
        $plugin->set('subscription', ['class' => SubscriptionService::class]);
        $plugin->set('plan', ['class' => PlanService::class]);
        $plugin->set('commercePackages', ['class' => PackageService::class]);
        $plugin->set('downloads', ['class' => DownloadService::class]);
        $plugin->set('updates', ['class' => UpdateService::class]);
        $plugin->set('featureGate', ['class' => FeatureGateService::class]);
    }
}
