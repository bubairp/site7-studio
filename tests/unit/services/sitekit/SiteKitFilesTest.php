<?php

namespace site7\studio\tests\unit\services\sitekit;

use Codeception\Test\Unit;
use site7\studio\services\sitekit\SiteKitFiles;

class SiteKitFilesTest extends Unit
{
    protected \UnitTester $tester;

    public function testStalePluginsAreRemoved(): void
    {
        $yaml = ['plugins' => ['seo' => ['enabled' => true], 'super-table' => ['enabled' => true]], 'system' => ['name' => 'X']];

        [$result, $removed] = SiteKitFiles::removeStalePlugins($yaml, ['seo', 'vite']);

        $this->assertSame(['seo'], array_keys($result['plugins']));
        $this->assertSame(['super-table'], $removed);
        $this->assertSame(['name' => 'X'], $result['system']);
    }

    public function testTargetKeepsItsOwnSystemAndEmail(): void
    {
        $kit = ['system' => ['name' => 'RP Craft'], 'email' => ['fromEmail' => 'kit@x'], 'sections' => ['a' => 1]];

        $result = SiteKitFiles::keepTargetSettings($kit, ['system' => ['name' => 'Fresh'], 'email' => ['fromEmail' => 'me@y']]);

        $this->assertSame('Fresh', $result['system']['name']);
        $this->assertSame('me@y', $result['email']['fromEmail']);
        $this->assertSame(['a' => 1], $result['sections']);
    }

    public function testMissingTargetSettingLeavesKitValue(): void
    {
        $result = SiteKitFiles::keepTargetSettings(['email' => ['fromEmail' => 'kit@x']], ['email' => null]);

        $this->assertSame('kit@x', $result['email']['fromEmail']);
    }

    public function testPathRepositories(): void
    {
        $composer = ['repositories' => [
            ['type' => 'composer', 'url' => 'https://composer.craftcms.com'],
            ['type' => 'vcs', 'url' => 'https://github.com/x/y.git'],
            ['type' => 'path', 'url' => 'plugins/ai-chat/'],
        ]];

        $this->assertSame(['plugins/ai-chat'], SiteKitFiles::pathRepositories($composer));
    }

    public function testEnvKeysIgnoreValuesAndComments(): void
    {
        $dotenv = "# comment\nCRAFT_APP_ID=abc\nexport API_KEY=\"secret=1\"\n\nBROKEN LINE\nPRIMARY_SITE_URL=\n";

        $this->assertSame(['CRAFT_APP_ID', 'API_KEY', 'PRIMARY_SITE_URL'], SiteKitFiles::envKeys($dotenv));
    }

    public function testMissingEnvKeys(): void
    {
        $this->assertSame(['STRIPE_SECRET_KEY'], SiteKitFiles::missingEnvKeys(['CRAFT_APP_ID', 'STRIPE_SECRET_KEY'], ['CRAFT_APP_ID', 'OTHER']));
    }

    public function testInstallSpecificConfigFilesDontTravel(): void
    {
        $this->assertFalse(SiteKitFiles::isTravellingConfigFile('db.php'));
        $this->assertFalse(SiteKitFiles::isTravellingConfigFile('license.key'));
        $this->assertFalse(SiteKitFiles::isTravellingConfigFile('license.key.1'));
        $this->assertFalse(SiteKitFiles::isTravellingConfigFile('project'));
        $this->assertTrue(SiteKitFiles::isTravellingConfigFile('app.php'));
        $this->assertTrue(SiteKitFiles::isTravellingConfigFile('htmlpurifier'));
    }

    public function testSiteIdsInReferenceTagsRoundTrip(): void
    {
        $uid = '7cffd3cf-93a1-487d-85d3-87c804278253';
        $json = '{"a":{"value":"{entry:5014@1:url}","type":"entry"},"b":"{asset:12@1}","c":"mail@1:x","d":"{entry:9@3:url}"}';

        $portable = SiteKitFiles::portableSiteRefs($json, [1 => $uid]);

        $this->assertStringContainsString('{entry:5014@{site:' . $uid . '}:url}', $portable);
        $this->assertStringContainsString('{asset:12@{site:' . $uid . '}}', $portable);
        $this->assertStringContainsString('"mail@1:x"', $portable, 'not a reference tag');
        $this->assertStringContainsString('{entry:9@3:url}', $portable, 'unknown site left alone');

        $resolved = SiteKitFiles::resolveSiteRefs($portable, [$uid => 2]);
        $this->assertSame(str_replace(['5014@1', '12@1'], ['5014@2', '12@2'], $json), $resolved);
    }

    public function testAddTreeSkipsNodeModules(): void
    {
        $root = sys_get_temp_dir() . '/site7_sitekit_' . uniqid();
        mkdir("$root/frontend/src", 0777, true);
        mkdir("$root/frontend/node_modules/x", 0777, true);
        file_put_contents("$root/frontend/src/index.js", 'x');
        file_put_contents("$root/frontend/package.json", '{}');
        file_put_contents("$root/frontend/node_modules/x/a.js", 'x');
        $zipPath = "$root/kit.zip";

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $count = SiteKitFiles::addTree($zip, $root, 'frontend');
        $zip->close();

        $zip->open($zipPath);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);

        $this->assertSame(2, $count);
        $this->assertSame(['files/frontend/package.json', 'files/frontend/src/index.js'], $names);

        exec('rm -rf ' . escapeshellarg($root));
    }
}
