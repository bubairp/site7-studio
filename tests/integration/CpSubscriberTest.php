<?php

namespace site7\studio\tests\integration;

use PHPUnit\Framework\TestCase;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUserPermissionsEvent;
use site7\studio\events\subscribers\CpSubscriber;
use site7\studio\events\RegisterNavigationEvent;
use site7\studio\events\RegisterPermissionsEvent;
use site7\studio\services\CpNavigationRegistry;
use site7\studio\services\CpPermissionRegistry;

class CpSubscriberTest extends TestCase
{
    public function testOnRegisterSite7Navigation(): void
    {
        $subscriber = new CpSubscriber();
        $registry = new CpNavigationRegistry();
        
        $event = new RegisterNavigationEvent();
        $event->registry = $registry;
        
        $subscriber->onRegisterSite7Navigation($event);
        
        // Use reflection to verify it was added
        $reflection = new \ReflectionClass($registry);
        $property = $reflection->getProperty('navItems');
        $property->setAccessible(true);
        $items = $property->getValue($registry);
        
        $this->assertCount(1, $items);
        $this->assertEquals('site7-studio', $items[0]['url']);
    }

    public function testOnRegisterSite7Permissions(): void
    {
        $subscriber = new CpSubscriber();
        $registry = new CpPermissionRegistry();
        
        $event = new RegisterPermissionsEvent();
        $event->registry = $registry;
        
        $subscriber->onRegisterSite7Permissions($event);
        
        $reflection = new \ReflectionClass($registry);
        $property = $reflection->getProperty('permissions');
        $property->setAccessible(true);
        $permissions = $property->getValue($registry);
        
        $this->assertArrayHasKey('Site7 Studio', $permissions);
    }
}
