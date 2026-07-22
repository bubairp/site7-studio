<?php

namespace site7\studio\events\subscribers;

use Craft;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
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
            [UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, 'onRegisterPermissions'],
            
            // Subscribe to Site7 Internal Registry Events
            [RegisterNavigationEvent::class, RegisterNavigationEvent::class, 'onRegisterSite7Navigation'],
            [RegisterPermissionsEvent::class, RegisterPermissionsEvent::class, 'onRegisterSite7Permissions'],
            
            // Subscribe to Widget Registration
            [\craft\services\Dashboard::class, \craft\services\Dashboard::EVENT_REGISTER_WIDGET_TYPES, 'onRegisterWidgetTypes'],
        ];
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
            'icon' => 'layer-group',
            'subnav' => [
                'dashboard' => ['label' => 'Dashboard', 'url' => 'site7-studio'],
                'library' => ['label' => 'Library', 'url' => 'site7-studio/library'],
                'settings' => ['label' => 'Settings', 'url' => 'site7-studio/settings'],
            ],
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

    /**
     * Registers Site7 Studio dashboard widgets.
     *
     * @param \craft\events\RegisterComponentTypesEvent $event
     */
    public function onRegisterWidgetTypes(\craft\events\RegisterComponentTypesEvent $event): void
    {
        $event->types[] = \site7\studio\widgets\LibraryWidget::class;
    }
}
