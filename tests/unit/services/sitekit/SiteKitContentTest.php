<?php

namespace site7\studio\tests\unit\services\sitekit;

use Codeception\Test\Unit;
use site7\studio\services\sitekit\SiteKitContent;

class SiteKitContentTest extends Unit
{
    protected \UnitTester $tester;

    /**
     * Building on a site with no content: an empty id() makes Craft abort the
     * asset query with QueryAbortedException, which crashed the build.
     */
    public function testNoAssetsMeansNoAssetQuery(): void
    {
        $zip = new \ZipArchive();
        $method = new \ReflectionMethod(SiteKitContent::class, 'exportAssetFiles');

        $this->assertSame([0, []], $method->invoke(new SiteKitContent(), $zip, []));
    }
}
