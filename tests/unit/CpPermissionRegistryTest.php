<?php

namespace site7\studio\tests\unit;

use PHPUnit\Framework\TestCase;
use site7\studio\services\CpPermissionRegistry;

class CpPermissionRegistryTest extends TestCase
{
    public function testRegisterPermission(): void
    {
        $registry = new CpPermissionRegistry();
        
        // Use reflection to bypass compilation dispatch for this simple unit test
        $reflection = new \ReflectionClass($registry);
        $property = $reflection->getProperty('isCompiled');
        $property->setAccessible(true);
        $property->setValue($registry, true);

        $registry->registerPermission('Site7 Studio', 'accessSite7Studio', ['label' => 'Access']);
        
        $permissions = $registry->getPermissions();
        $this->assertArrayHasKey('Site7 Studio', $permissions);
        $this->assertArrayHasKey('accessSite7Studio', $permissions['Site7 Studio']);
        $this->assertEquals('Access', $permissions['Site7 Studio']['accessSite7Studio']['label']);
    }

    public function testDuplicatePrevention(): void
    {
        $registry = new CpPermissionRegistry();
        
        $reflection = new \ReflectionClass($registry);
        $property = $reflection->getProperty('isCompiled');
        $property->setAccessible(true);
        $property->setValue($registry, true);

        $registry->registerPermission('Site7 Studio', 'accessSite7Studio', ['label' => 'Access']);
        $registry->registerPermission('Site7 Studio', 'accessSite7Studio', ['label' => 'Overwritten']);
        
        $permissions = $registry->getPermissions();
        $this->assertCount(1, $permissions['Site7 Studio']);
        $this->assertEquals('Overwritten', $permissions['Site7 Studio']['accessSite7Studio']['label']);
    }
}
