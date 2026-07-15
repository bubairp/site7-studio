<?php

namespace site7\studio\tests\unit;

use PHPUnit\Framework\TestCase;
use site7\studio\services\CpNavigationRegistry;

class CpNavigationRegistryTest extends TestCase
{
    public function testRegisterNavItem(): void
    {
        $registry = new CpNavigationRegistry();
        
        // Use reflection to bypass compilation dispatch for this simple unit test
        $reflection = new \ReflectionClass($registry);
        $property = $reflection->getProperty('isCompiled');
        $property->setAccessible(true);
        $property->setValue($registry, true);

        $registry->registerNavItem(['url' => 'test-url', 'label' => 'Test']);
        
        $items = $registry->getNavItems();
        $this->assertCount(1, $items);
        $this->assertEquals('test-url', $items[0]['url']);
    }

    public function testDuplicatePrevention(): void
    {
        $registry = new CpNavigationRegistry();
        
        $reflection = new \ReflectionClass($registry);
        $property = $reflection->getProperty('isCompiled');
        $property->setAccessible(true);
        $property->setValue($registry, true);

        $registry->registerNavItem(['url' => 'test-url', 'label' => 'Test']);
        $registry->registerNavItem(['url' => 'test-url', 'label' => 'Test 2']);
        
        $items = $registry->getNavItems();
        $this->assertCount(1, $items);
        $this->assertEquals('Test', $items[0]['label']); // First one should be retained
    }
}
