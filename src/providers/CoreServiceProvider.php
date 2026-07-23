<?php

namespace site7\studio\providers;

use site7\studio\Site7Studio;
use site7\studio\services\ConfigService;
use site7\studio\services\LogService;
use site7\studio\services\CacheService;
use site7\studio\services\PackageManagerService;
use site7\studio\services\CraftResourceService;
use site7\studio\services\MarketplaceService;

/**
 * Class CoreServiceProvider
 *
 * Registers the Core Infrastructure (Config, Logging, Cache).
 */
class CoreServiceProvider implements ServiceProviderInterface
{
    /**
     * @inheritdoc
     */
    public function register(Site7Studio $plugin): void
    {
        $plugin->set('configService', [
            'class' => ConfigService::class,
        ]);
        
        $plugin->set('log', [
            'class' => LogService::class,
        ]);
        
        $plugin->set('cache', [
            'class' => CacheService::class,
        ]);
        
        $plugin->set('packageManager', [
            'class' => PackageManagerService::class,
        ]);
        
        $plugin->set('craftResourceGenerator', [
            'class' => CraftResourceService::class,
        ]);
        
        $plugin->set('packageUsage', [
            'class' => \site7\studio\services\PackageUsageService::class,
        ]);

        $plugin->set('marketplace', [
            'class' => MarketplaceService::class,
        ]);
    }
}
