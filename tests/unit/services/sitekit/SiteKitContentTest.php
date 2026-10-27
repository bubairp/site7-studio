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
    public function testEmptyFieldValuesCountAsNoValue(): void
    {
        $saved = ['a' => 'Title', 'b' => [], 'c' => false, 'seo' => ['description' => [], 'title' => 'T']];
        $installed = ['a' => 'Title', 'seo' => ['description' => '', 'title' => 'T']];
        $this->assertSame(SiteKitContent::comparableContent($installed), SiteKitContent::comparableContent($saved));
        $this->assertNotSame(SiteKitContent::comparableContent(['a' => 'Mine']), SiteKitContent::comparableContent($installed), 'a real edit still differs');
    }

    public function testAnOlderVersionReadsAsThisSiteHoldsIt(): void
    {
        $installed = ['a' => 'Title', 'gone' => 'Guy Hawkins', 'cats' => [404], 'assets' => [5340]];
        $live = ['a' => 'Title', 'cats' => [], 'assets' => [5340]];
        $this->assertSame(
            SiteKitContent::comparableContent($live),
            SiteKitContent::comparableContent($installed, ['a' => true, 'cats' => true, 'assets' => true], [404 => true])
        );
    }

    public function testSignaturesIgnoreEmptyValuesAndRelationsToMissingElements(): void
    {
        $dir = sys_get_temp_dir() . '/site7-sig-' . uniqid();
        $write = function(string $name, array $sites, array $relations) use ($dir) {
            mkdir("{$dir}/{$name}/tables", 0777, true);
            file_put_contents("{$dir}/{$name}/tables/elements_sites.json", json_encode($sites));
            file_put_contents("{$dir}/{$name}/tables/relations.json", json_encode($relations));
        };
        $write('installed', [['elementId' => 7, 'siteId' => 1, 'title' => 'P', 'content' => json_encode(['a' => 'x', 'cats' => [404]])]],
            [['id' => 1, 'sourceId' => 7, 'targetId' => 404, 'fieldId' => 3, 'sortOrder' => 1]]);
        $write('live', [['elementId' => 7, 'siteId' => 1, 'title' => 'P', 'content' => json_encode(['a' => 'x', 'cats' => [], 'new' => false])]], []);

        $this->assertNotSame(SiteKitContent::signatures("{$dir}/installed")[7], SiteKitContent::signatures("{$dir}/live")[7]);
        $this->assertSame(
            SiteKitContent::signatures("{$dir}/installed", ['fieldKeys' => null, 'missingIds' => [404 => true]])[7],
            SiteKitContent::signatures("{$dir}/live")[7]
        );
        array_map('unlink', glob("{$dir}/*/tables/*.json"));
        array_map('rmdir', glob("{$dir}/*/tables"));
        array_map('rmdir', glob("{$dir}/*"));
        rmdir($dir);
    }

    public function testNoAssetsMeansNoAssetQuery(): void
    {
        $zip = new \ZipArchive();
        $method = new \ReflectionMethod(SiteKitContent::class, 'exportAssetFiles');

        $this->assertSame([0, []], $method->invoke(new SiteKitContent(), $zip, []));
    }

    /**
     * SEO sitemap rows point at a section or category group by ID in url: on import the
     * exported UID becomes this site's ID, a row whose section the site lacks is left
     * out, and custom URL rows stay as they are.
     */
    public function testSeoSitemapRowsResolveToThisSitesIds(): void
    {
        $resolveUid = function(string $table, string $uid): int {
            $known = ['sections' => ['uid-blogs' => 42], 'categorygroups' => ['uid-topics' => 7]];
            if (!isset($known[$table][$uid])) {
                throw new \Exception("{$table} {$uid} missing");
            }
            return $known[$table][$uid];
        };
        $rows = [
            ['id' => 1, 'group' => 'sections', 'url' => '@uid:uid-blogs'],
            ['id' => 2, 'group' => 'sections', 'url' => '@uid:uid-gone'],
            ['id' => 3, 'group' => 'categories', 'url' => '@uid:uid-topics'],
            ['id' => 4, 'group' => 'customUrls', 'url' => '/press'],
        ];

        $out = SiteKitContent::resolveRowReferences('seo_sitemap', $rows, $resolveUid);

        $this->assertSame([1, 3, 4], array_column($out, 'id'), 'the row for a missing section is left out');
        $this->assertSame(['42', '7', '/press'], array_column($out, 'url'));
        $this->assertSame($rows, SiteKitContent::resolveRowReferences('sitemaps', $rows, $resolveUid), 'other tables untouched');
    }

    /**
     * A kit's seo_sitemap rows for sections this site doesn't have were never imported,
     * so the update compares only the rows that can be here (site7-qa kept the table as
     * "edited here" on kit 1.2.1 -> 1.3.0: 6 of 13 rows live).
     */
    public function testTableSignaturesCanLeaveOutRowsForMissingTargets(): void
    {
        $dir = sys_get_temp_dir() . '/site7-tables-' . uniqid();
        $write = function(string $name, array $rows) use ($dir) {
            mkdir("{$dir}/{$name}/plugin-tables", 0777, true);
            file_put_contents("{$dir}/{$name}/plugin-tables/seo_sitemap.json", json_encode($rows));
        };
        $row = fn(int $id, string $uid, int $enabled = 1) => ['id' => $id, 'group' => 'sections', 'url' => "@uid:{$uid}", 'enabled' => $enabled, 'dateUpdated' => "2026-10-26 0{$id}:00:00"];
        $write('kit', [$row(1, 'uid-pages'), $row(2, 'uid-blog')]);
        $write('next', [$row(1, 'uid-pages'), $row(2, 'uid-blog', 0)]);
        $write('live', [$row(1, 'uid-pages')]);
        $hereOnly = function(string $table, string $uid): string {
            if ($uid !== 'uid-pages') {
                throw new \Exception("{$table} {$uid} missing");
            }
            return "@uid:{$uid}";
        };
        $sig = fn(string $name, ?callable $resolve = null) => SiteKitContent::pluginTableSignatures("{$dir}/{$name}", ['seo_sitemap'], $resolve)['seo_sitemap'];

        $this->assertNotSame($sig('kit'), $sig('live'), 'all rows: looks edited here');
        $this->assertSame($sig('kit', $hereOnly), $sig('live'), 'rows that can be here: unchanged');
        $this->assertSame($sig('kit', $hereOnly), $sig('next', $hereOnly), "an upstream change to a row that can't be here is no change here");
        array_map('unlink', glob("{$dir}/*/plugin-tables/*.json"));
        array_map('rmdir', glob("{$dir}/*/plugin-tables"));
        array_map('rmdir', glob("{$dir}/*"));
        rmdir($dir);
    }
}
