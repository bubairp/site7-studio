<?php

namespace site7\studio\tests\unit\services\sitekit;

use Codeception\Test\Unit;
use site7\studio\services\sitekit\ComposerFiles;

class ComposerFilesTest extends Unit
{
    protected \UnitTester $tester;

    /** rp-craft's files: Site7 Studio and a plugin from local folders, a Git plugin, Craft's own repository. */
    private function authorSite(): array
    {
        $json = [
            'require' => ['craftcms/cms' => '5.10.8.1', 'site7/studio' => '*', 'remoteprogrammer/ai-chat' => '^1.0', 'remoteprogrammer/simple-rp-menu' => '^1.0.2'],
            'repositories' => [
                ['type' => 'composer', 'url' => 'https://composer.craftcms.com', 'canonical' => false],
                ['type' => 'vcs', 'url' => 'https://github.com/rpqa99/craft5-simple-menu.git'],
                ['type' => 'path', 'url' => 'plugins/rp/ai-chat', 'options' => ['symlink' => true]],
                ['type' => 'path', 'url' => 'plugins/site7-studio', 'options' => ['symlink' => true]],
            ],
        ];
        $lock = ['content-hash' => 'x', 'packages' => [
            ['name' => 'craftcms/cms', 'version' => '5.10.8.1'],
            ['name' => 'remoteprogrammer/ai-chat', 'version' => '1.0.0', 'dist' => ['type' => 'path', 'url' => 'plugins/rp/ai-chat', 'reference' => 'a'], 'transport-options' => ['symlink' => true, 'relative' => true]],
            ['name' => 'remoteprogrammer/simple-rp-menu', 'version' => '1.0.2', 'source' => ['type' => 'git', 'url' => 'https://github.com/rpqa99/craft5-simple-menu.git', 'reference' => 'b']],
            ['name' => 'site7/studio', 'version' => '1.0.0', 'dist' => ['type' => 'path', 'url' => 'plugins/site7-studio', 'reference' => 'c'], 'transport-options' => ['symlink' => true, 'relative' => true]],
        ]];

        return [$json, $lock];
    }

    /** A customer site that installed Site7 Studio from its Git repository. */
    private function customerSite(): array
    {
        $json = [
            'require' => ['craftcms/cms' => '5.10.8.1', 'site7/studio' => 'dev-main'],
            'repositories' => ['site7-studio' => ['type' => 'vcs', 'url' => 'https://github.com/bubairp/site7-studio.git']],
        ];
        $lock = ['content-hash' => 'y', 'packages' => [
            ['name' => 'craftcms/cms', 'version' => '5.10.8.1'],
            ['name' => 'site7/studio', 'version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://github.com/bubairp/site7-studio.git', 'reference' => 'd']],
        ]];

        return [$json, $lock];
    }

    public function testTheThemeLeavesOutSite7Studio(): void
    {
        [$json, $lock] = ComposerFiles::withoutPackage(...$this->authorSite(), ...['site7/studio']);

        $this->assertArrayNotHasKey('site7/studio', $json['require']);
        $this->assertSame(['https://composer.craftcms.com', 'https://github.com/rpqa99/craft5-simple-menu.git', 'plugins/rp/ai-chat'], array_column($json['repositories'], 'url'));
        $this->assertNotContains('site7/studio', array_column($lock['packages'], 'name'));
        $this->assertSame(ComposerFiles::contentHash($json), $lock['content-hash']);
    }

    public function testAGitPackageIsLeftOutWithItsRepository(): void
    {
        [$json, $lock] = ComposerFiles::withoutPackage(...$this->authorSite(), ...['remoteprogrammer/simple-rp-menu']);

        $this->assertNotContains('https://github.com/rpqa99/craft5-simple-menu.git', array_column($json['repositories'], 'url'));
        $this->assertNotContains('remoteprogrammer/simple-rp-menu', array_column($lock['packages'], 'name'));
    }

    public function testAnInstallPutsBackTheSitesOwnSite7Studio(): void
    {
        [$json, $lock] = ComposerFiles::withoutPackage(...$this->authorSite(), ...['site7/studio']);
        [$json, $lock] = ComposerFiles::withPackageFrom($json, $lock, ...$this->customerSite(), ...['site7/studio']);

        $this->assertSame('dev-main', $json['require']['site7/studio']);
        $this->assertContains('https://github.com/bubairp/site7-studio.git', array_column($json['repositories'], 'url'));
        $this->assertNotContains('plugins/site7-studio', array_column($json['repositories'], 'url'));
        $site7 = array_values(array_filter($lock['packages'], fn($p) => $p['name'] === 'site7/studio'));
        $this->assertCount(1, $site7);
        $this->assertSame('d', $site7[0]['source']['reference']);
        $this->assertSame(ComposerFiles::contentHash($json), $lock['content-hash']);
    }

    public function testAThemeThatStillHasSite7StudioGetsTheSitesOneInstead(): void
    {
        [$json, $lock] = ComposerFiles::withPackageFrom(...$this->authorSite(), ...[...$this->customerSite(), 'site7/studio']);

        $this->assertNotContains('plugins/site7-studio', array_column($json['repositories'], 'url'));
        $this->assertSame(['dev-main'], array_column(array_filter($lock['packages'], fn($p) => $p['name'] === 'site7/studio'), 'version'));
    }

    public function testBundledPluginFoldersInstallAsCopies(): void
    {
        [$json, $lock] = ComposerFiles::copyPathRepositories(...$this->authorSite(), ...[['plugins/rp/ai-chat']]);

        $this->assertSame(['symlink' => false], $json['repositories'][2]['options']);
        $this->assertTrue($json['repositories'][3]['options']['symlink'], 'other folders unchanged');
        $this->assertSame(['symlink' => false, 'relative' => true], $lock['packages'][1]['transport-options']);
    }

    public function testSite7StudiosFolderInAnOldTheme(): void
    {
        [, $lock] = $this->authorSite();
        $this->assertSame(['plugins/site7-studio'], ComposerFiles::packagePaths($lock, 'site7/studio'));
        $this->assertSame([], ComposerFiles::packagePaths($lock, 'remoteprogrammer/simple-rp-menu'));
    }

    /** Checked against composer.lock files Composer wrote: rp-craft's at 5446729c… */
    public function testTheContentHashIsComposers(): void
    {
        $file = dirname(__DIR__, 6) . '/composer.json';
        $lock = dirname(__DIR__, 6) . '/composer.lock';
        if (!is_file($file) || !is_file($lock)) {
            $this->markTestSkipped('Needs the site this plugin is installed in.');
        }
        $this->assertSame(json_decode(file_get_contents($lock), true)['content-hash'], ComposerFiles::contentHash(json_decode(file_get_contents($file), true)));
    }
}
