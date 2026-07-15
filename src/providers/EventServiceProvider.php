<?php

namespace site7\studio\providers;

use site7\studio\Site7Studio;
use site7\studio\events\EventDispatcher;

/**
 * Class EventServiceProvider
 *
 * Registers the Event System infrastructure.
 */
class EventServiceProvider implements ServiceProviderInterface
{
    /**
     * @inheritdoc
     */
    public function register(Site7Studio $plugin): void
    {
        $plugin->set('eventDispatcher', [
            'class' => EventDispatcher::class,
        ]);
    }
}
