<?php

namespace site7\studio\tests\unit\services\starterkit;

use Codeception\Test\Unit;
use site7\studio\services\starterkit\KitBuilder;

class KitBuilderTest extends Unit
{
    protected \UnitTester $tester;

    public function testAKitIsNamedAfterItsSite(): void
    {
        $this->assertSame('rp-craft-starter-kit', KitBuilder::handleFor('RP Craft'));
    }

    public function testStarterKitIsNotRepeated(): void
    {
        $this->assertSame('rp-craft-starter-kit', KitBuilder::handleFor('RP Craft Starter Kit'));
    }
}
