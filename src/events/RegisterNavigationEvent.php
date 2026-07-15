<?php

namespace site7\studio\events;

use site7\studio\services\CpNavigationRegistry;

/**
 * RegisterNavigationEvent
 *
 * Dispatched when the CpNavigationRegistry is compiling CP navigation items.
 */
class RegisterNavigationEvent extends BaseEvent
{
    /**
     * @var CpNavigationRegistry The registry instance to register items with.
     */
    public CpNavigationRegistry $registry;
}
