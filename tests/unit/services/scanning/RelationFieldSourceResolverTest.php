<?php

namespace site7\studio\tests\unit\services\scanning;

use Codeception\Test\Unit;
use site7\studio\services\scanning\RelationFieldSourceResolver;

/**
 * Covers RelationFieldSourceResolver's pure source-string parsing logic
 * (extracted out of WebsiteImportService during the CraftResourceRegistry
 * refactor) - static and Craft-app-independent by design, so tested
 * directly rather than via reflection (matching this repo's existing
 * convention - see CraftResourceDiscoveryServiceTest).
 */
class RelationFieldSourceResolverTest extends Unit
{
    protected \UnitTester $tester;

    public function testResolveUidsExtractsMatchingPrefixedSources()
    {
        $uids = RelationFieldSourceResolver::resolveUids(['volume:uid-1', 'volume:uid-2', 'somethingElse'], 'volume:', fn() => []);

        sort($uids);
        $this->assertSame(['uid-1', 'uid-2'], $uids);
    }

    public function testResolveUidsIgnoresSourcesWithADifferentPrefix()
    {
        $uids = RelationFieldSourceResolver::resolveUids(['section:uid-1', 'group:uid-2'], 'volume:', fn() => []);

        $this->assertSame([], $uids);
    }

    public function testResolveUidsNullSourcesYieldsNoUids()
    {
        $uids = RelationFieldSourceResolver::resolveUids(null, 'volume:', fn() => []);

        $this->assertSame([], $uids);
    }

    public function testResolveUidsWildcardResolvesToEveryResourceFromTheCallback()
    {
        $fakeVolumeA = (object)['uid' => 'uid-a'];
        $fakeVolumeB = (object)['uid' => 'uid-b'];

        $uids = RelationFieldSourceResolver::resolveUids('*', 'volume:', fn() => [$fakeVolumeA, $fakeVolumeB]);

        sort($uids);
        $this->assertSame(['uid-a', 'uid-b'], $uids);
    }

    public function testCollectUidsMergesIntoExistingMapRatherThanOverwriting()
    {
        $uids = ['uid-existing' => true];
        RelationFieldSourceResolver::collectUids(['volume:uid-new'], 'volume:', $uids, fn() => []);

        $this->assertSame(['uid-existing' => true, 'uid-new' => true], $uids);
    }
}
