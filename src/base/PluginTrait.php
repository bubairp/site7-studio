<?php

namespace site7\studio\base;

use site7\studio\Site7Studio;
use craft\base\Component;

/**
 * Trait PluginTrait
 *
 * Provides Service Locator functionality for the plugin.
 */
trait PluginTrait
{
    /**
     * Helper to get a component by ID from the plugin.
     *
     * @param string $id
     * @return Component|null
     */
    public function getService(string $id): ?Component
    {
        return $this->get($id);
    }

    /**
     * @return \site7\studio\services\CpNavigationRegistry|null
     */
    public function getNavigation(): ?Component
    {
        return $this->get('navigation');
    }

    /**
     * @return \site7\studio\services\CpPermissionRegistry|null
     */
    public function getPermissions(): ?Component
    {
        return $this->get('permissions');
    }
}
