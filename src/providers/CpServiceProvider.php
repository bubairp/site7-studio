<?php

namespace site7\studio\providers;

use site7\studio\Site7Studio;
use site7\studio\events\subscribers\CpSubscriber;
use site7\studio\services\CpNavigationRegistry;
use site7\studio\services\CpPermissionRegistry;

/**
 * Class CpServiceProvider
 *
 * Bootstraps and registers Control Panel (CP) specific services, such as navigation
 * and permissions, into the Service Locator.
 */
class CpServiceProvider implements ServiceProviderInterface
{
    /**
     * @inheritdoc
     */
    public function register(Site7Studio $plugin): void
    {
        // Register Registries
        $plugin->set('navigation', CpNavigationRegistry::class);
        $plugin->set('permissions', CpPermissionRegistry::class);

        // Register CP Subscriber
        $plugin->getService('eventDispatcher')->addSubscriber(new CpSubscriber());
    }
}
