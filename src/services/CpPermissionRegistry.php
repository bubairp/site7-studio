<?php

namespace site7\studio\services;

use craft\base\Component;
use site7\studio\Site7Studio;
use site7\studio\events\RegisterPermissionsEvent;

/**
 * Class CpPermissionRegistry
 *
 * Collects and provides CP user permissions for Site7 Studio.
 */
class CpPermissionRegistry extends Component
{
    private array $permissions = [];
    private bool $isCompiled = false;

    /**
     * Registers a permission to the registry.
     *
     * @param string $category The category heading for the permission.
     * @param string $permissionName The unique handle for the permission.
     * @param array $config The permission definition array (e.g. ['label' => '...']).
     * @return void
     */
    public function registerPermission(string $category, string $permissionName, array $config): void
    {
        if (!isset($this->permissions[$category])) {
            $this->permissions[$category] = [];
        }
        
        // This associative array assignment naturally prevents duplicates by overwriting
        $this->permissions[$category][$permissionName] = $config;
    }

    /**
     * Retrieves all registered permissions, triggering the registration event
     * if they haven't been compiled yet.
     *
     * @return array
     */
    public function getPermissions(): array
    {
        if (!$this->isCompiled) {
            $event = new RegisterPermissionsEvent();
            $event->registry = $this;

            Site7Studio::getInstance()->getService('eventDispatcher')->dispatch($event);

            $this->isCompiled = true;
        }

        return $this->permissions;
    }
}
