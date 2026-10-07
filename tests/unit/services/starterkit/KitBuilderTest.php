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

    public function testABaseKitKeepsOnlyMenuItemsForItsPages(): void
    {
        $item = fn(int $id, int $parent, ?int $entry, ?string $url) => ['id' => $id, 'parent_id' => $parent, 'entry_id' => $entry, 'custom_url' => $url];
        $items = [
            $item(1, 0, 26, null),                  // Home, by entry
            $item(2, 0, null, '/about-us'),          // About, by URI
            $item(3, 0, 1024, null),                 // Blog: not in the kit
            $item(4, 0, null, null),                 // "Services" group
            $item(5, 4, 1782, null),                 //   its pages: not in the kit
            $item(6, 0, null, null),                 // a text item (phone number)
            $item(7, 0, null, 'http://rpcraft.local/contact'), // the author site's address
            $item(8, 0, null, '#'),
            $item(9, 3, 26, null),                   // under a dropped item
        ];
        $kept = array_column(KitBuilder::menuItemsFor($items, [26, 6254], ['__home__', 'about-us', 'contact']), 'id');
        $this->assertSame([1, 2, 6, 8], $kept);
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
