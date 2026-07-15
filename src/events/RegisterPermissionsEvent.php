<?php

namespace site7\studio\events;

use site7\studio\services\CpPermissionRegistry;

/**
 * RegisterPermissionsEvent
 *
 * Dispatched when the CpPermissionRegistry is compiling CP user permissions.
 */
class RegisterPermissionsEvent extends BaseEvent
{
    /**
     * @var CpPermissionRegistry The registry instance to register permissions with.
     */
    public CpPermissionRegistry $registry;
}
