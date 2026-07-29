<?php

namespace site7\studio\tests\unit\services\scanning;

use Codeception\Test\Unit;
use site7\studio\services\scanning\NavigationScanner;

/**
 * Covers NavigationScanner::classNameMatchesNavigationPrefix()'s pure logic -
 * no live Craft field object/app needed (matching this repo's existing
 * convention of only unit-testing the Craft-independent pieces of the
 * import/scanning services - see CraftResourceDiscoveryServiceTest).
 */
class NavigationScannerTest extends Unit
{
    protected \UnitTester $tester;

    public function testMatchesTheKnownNavigationPluginFieldClass()
    {
        $this->assertTrue(NavigationScanner::classNameMatchesNavigationPrefix('remoteprogrammer\\simplerpmenu\\fields\\SimpleRpMenu'));
    }

    public function testDoesNotMatchAnOrdinaryCraftField()
    {
        $this->assertFalse(NavigationScanner::classNameMatchesNavigationPrefix('craft\\fields\\PlainText'));
    }

    public function testDoesNotMatchAnUnrelatedPlugin()
    {
        $this->assertFalse(NavigationScanner::classNameMatchesNavigationPrefix('ether\\seo\\fields\\SeoField'));
    }
}
