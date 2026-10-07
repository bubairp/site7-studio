<?php

namespace site7\studio\tests\unit\services\support;

use Codeception\Test\Unit;
use site7\studio\services\support\CraftVersion;

class CraftVersionTest extends Unit
{
    protected \UnitTester $tester;

    public function testTheSameMajorVersionIsCompatible(): void
    {
        $this->assertTrue(CraftVersion::isCompatible('5.10.8.1', '5.10.8.1'));
        $this->assertTrue(CraftVersion::isCompatible('5.10.8.1', '5.10.9'));
        $this->assertTrue(CraftVersion::isCompatible('5.10.8.1', '5.11.4'), 'a later minor');
        $this->assertTrue(CraftVersion::isCompatible('5.10.8.1', '5.2.0'), 'an earlier minor');
    }

    public function testAnotherMajorVersionIsNot(): void
    {
        $this->assertFalse(CraftVersion::isCompatible('5.10.8.1', '6.0.0'));
        $this->assertFalse(CraftVersion::isCompatible('5.10.8.1', '4.14.2'));
    }

    public function testAPackageWithoutAVersionInstallsAnywhere(): void
    {
        $this->assertTrue(CraftVersion::isCompatible(null, '6.0.0'));
        $this->assertTrue(CraftVersion::isCompatible('', '6.0.0'));
    }

    public function testTheRangeIsTheMajorVersion(): void
    {
        $this->assertSame('5.x', CraftVersion::range('5.10.8.1'));
    }
}
