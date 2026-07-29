<?php

namespace site7\studio\tests\unit\services\import;

use Codeception\Test\Unit;
use ReflectionMethod;
use site7\studio\services\import\WebsiteImportService;

/**
 * Covers WebsiteImportService::collectRelationSourceUids()'s pure
 * source-string parsing logic (Website Starter Kit System Phase 2) - static
 * and Craft-app-independent by design, so it's tested directly via
 * reflection rather than needing a live Craft field/site (matching this
 * repo's existing convention - see CraftResourceDiscoveryServiceTest).
 */
class WebsiteImportServiceTest extends Unit
{
    protected \UnitTester $tester;

    private function invoke(string|array|null $sources, string $prefix, array &$uids, callable $allWhenWildcard): void
    {
        $method = new ReflectionMethod(WebsiteImportService::class, 'collectRelationSourceUids');
        $method->setAccessible(true);
        $method->invokeArgs(null, [$sources, $prefix, &$uids, $allWhenWildcard]);
    }

    public function testExtractsMatchingPrefixedSources()
    {
        $uids = [];
        $this->invoke(['volume:uid-1', 'volume:uid-2', 'somethingElse'], 'volume:', $uids, fn() => []);

        $this->assertSame(['uid-1' => true, 'uid-2' => true], $uids);
    }

    public function testIgnoresSourcesWithADifferentPrefix()
    {
        $uids = [];
        $this->invoke(['section:uid-1', 'group:uid-2'], 'volume:', $uids, fn() => []);

        $this->assertSame([], $uids);
    }

    public function testNullSourcesYieldsNoUids()
    {
        $uids = [];
        $this->invoke(null, 'volume:', $uids, fn() => []);

        $this->assertSame([], $uids);
    }

    public function testWildcardResolvesToEveryResourceFromTheCallback()
    {
        $uids = [];
        $fakeVolumeA = (object)['uid' => 'uid-a'];
        $fakeVolumeB = (object)['uid' => 'uid-b'];

        $this->invoke('*', 'volume:', $uids, fn() => [$fakeVolumeA, $fakeVolumeB]);

        $this->assertSame(['uid-a' => true, 'uid-b' => true], $uids);
    }

    public function testMergesIntoExistingUidsRatherThanOverwriting()
    {
        $uids = ['uid-existing' => true];
        $this->invoke(['volume:uid-new'], 'volume:', $uids, fn() => []);

        $this->assertSame(['uid-existing' => true, 'uid-new' => true], $uids);
    }
}
