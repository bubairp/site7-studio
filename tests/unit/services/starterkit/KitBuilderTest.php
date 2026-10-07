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

    public function testAPackTakesAWholeSection(): void
    {
        $post = ['section' => 'blogs', 'uri' => 'blogs/new-goal-4'];
        $this->assertTrue(KitBuilder::inPages($post, ['blogs']));
        $this->assertFalse(KitBuilder::inPages($post, ['products']));
    }

    public function testAPackTakesOnePageOfASection(): void
    {
        $listing = ['section' => 'standardPages', 'uri' => 'blogs'];
        $this->assertTrue(KitBuilder::inPages($listing, ['blogs', 'standardPages/blogs']));
        $this->assertFalse(KitBuilder::inPages(['section' => 'standardPages', 'uri' => 'about'], ['standardPages/blogs']));
        $this->assertFalse(KitBuilder::inPages($listing, ['standardPages/price']));
    }
}
