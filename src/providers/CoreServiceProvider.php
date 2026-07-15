<?php

namespace site7\studio\providers;

use site7\studio\Site7Studio;
use site7\studio\services\ConfigService;
use site7\studio\services\LogService;
use site7\studio\services\CacheService;

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
    }
}
