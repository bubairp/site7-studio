<?php

namespace site7\studio\events\subscribers;

use Craft;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\twig\variables\Cp;
use site7\studio\events\EventSubscriberInterface;
use site7\studio\events\RegisterNavigationEvent;
use site7\studio\events\RegisterPermissionsEvent;
use site7\studio\Site7Studio;

/**
 * Class CpSubscriber
 *
 * Handles control panel integration events, acting as the bridge between Craft CMS
 * core events and Site7 Studio's internal event registries.
 */
class CpSubscriber implements EventSubscriberInterface
{
    /**
     * @inheritdoc
     */
    public function getSubscribedEvents(): array
    {
        return [
            // Subscribe to Craft Core Events
            [Cp::class, Cp::EVENT_REGISTER_CP_NAV_ITEMS, 'onRegisterCpNavItems'],
            [UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, 'onRegisterPermissions'],
            
            // Subscribe to Site7 Internal Registry Events
            [RegisterNavigationEvent::class, RegisterNavigationEvent::class, 'onRegisterSite7Navigation'],
            [RegisterPermissionsEvent::class, RegisterPermissionsEvent::class, 'onRegisterSite7Permissions'],
        ];
    }

    /**
     * Handles Craft's native CP Nav registration event.
     * We retrieve all compiled items from our internal NavigationRegistry and inject them.
     *
     * @param RegisterCpNavItemsEvent $event
     */
    public function onRegisterCpNavItems(RegisterCpNavItemsEvent $event): void
    {
        $items = Site7Studio::getInstance()->getNavigation()->getNavItems();
        
        foreach ($items as $item) {
            $event->navItems[] = $item;
        }
    }

    /**
     * Handles Craft's native Permission registration event.
     * We retrieve all compiled permissions from our internal PermissionRegistry and inject them.
     *
     * @param RegisterUserPermissionsEvent $event
     */
    public function onRegisterPermissions(RegisterUserPermissionsEvent $event): void
    {
        $permissions = Site7Studio::getInstance()->getPermissions()->getPermissions();
        
        foreach ($permissions as $category => $categoryPermissions) {
            $event->permissions[$category] = $categoryPermissions;
        }
    }

    /**
     * Registers Site7 Studio's base navigation item into its own registry.
     *
     * @param RegisterNavigationEvent $event
     */
    public function onRegisterSite7Navigation(RegisterNavigationEvent $event): void
    {
        $event->registry->registerNavItem([
            'url' => 'site7-studio',
            'label' => 'Site7 Studio',
            'icon' => '@site7/studio/icon.svg',
        ]);
    }

    /**
     * Registers Site7 Studio's base permissions into its own registry.
     *
     * @param RegisterPermissionsEvent $event
     */
    public function onRegisterSite7Permissions(RegisterPermissionsEvent $event): void
    {
        $event->registry->registerPermission(
            'Site7 Studio', 
            'accessSite7Studio', 
            ['label' => 'Access Site7 Studio']
        );
    }
}
