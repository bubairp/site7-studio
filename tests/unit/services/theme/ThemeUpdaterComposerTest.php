<?php

namespace site7\studio\tests\unit\services\theme;

use Codeception\Test\Unit;
use site7\studio\services\sitekit\ComposerFiles;
use site7\studio\services\theme\ThemeUpdater;

/**
 * A Theme update keeps the site's own Site7 Studio in composer.json/lock
 * (docs/43 #51): site7-qa lost the plugin when the Theme's Composer files,
 * which never list it, were copied over the site's before installPackages()
 * read them.
 */
class ThemeUpdaterComposerTest extends Unit
{
    protected \UnitTester $tester;

    private string $tmp;

    protected function _before()
    {
        $this->tmp = sys_get_temp_dir() . '/site7-theme-composer-' . uniqid();
        mkdir("{$this->tmp}/runtime", 0777, true);
        $runtime = "{$this->tmp}/runtime";
        \Craft::$app = new class ($runtime) {
            public function __construct(private readonly string $runtime)
            {
            }

            public function getRuntimePath(): string
            {
                return $this->runtime;
            }

            // craft\helpers\FileHelper::createDirectory() reads the default directory mode.
            public function getConfig(): object
            {
                return new class {
                    public function getGeneral(): object
                    {
                        return (object)['defaultDirMode' => 0775, 'defaultFileMode' => null];
                    }
                };
            }
        };
    }

    protected function _after()
    {
        \Craft::$app = null;
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    private function write(string $dir, array $json, array $lock): void
    {
        @mkdir($dir, 0777, true);
        file_put_contents("{$dir}/composer.json", ComposerFiles::encode($json));
        file_put_contents("{$dir}/composer.lock", ComposerFiles::encode($lock));
    }

    /** Theme files as built on the author site: no Site7 Studio (docs/49 §2d). */
    private function theme(array $extraRequire = []): array
    {
        $json = [
            'require' => array_merge(['craftcms/cms' => '5.10.8.1', 'remoteprogrammer/htmlsitemap' => '^2.0'], $extraRequire),
            'repositories' => [['type' => 'path', 'url' => 'plugins/rp/htmlsitemap', 'options' => ['symlink' => false]]],
        ];
        $packages = [['name' => 'craftcms/cms', 'version' => '5.10.8.1'], ['name' => 'remoteprogrammer/htmlsitemap', 'version' => '2.0.0']];
        foreach ($extraRequire as $name => $version) {
            $packages[] = ['name' => $name, 'version' => ltrim($version, '^')];
        }

        return [$json, ['content-hash' => ComposerFiles::contentHash($json), 'packages' => $packages]];
    }

    /** The installed Theme's files plus Site7 Studio from its Git repository, as on site7-qa. */
    private function siteWithSite7(array $json, array $lock): array
    {
        $json['require']['site7/studio'] = 'dev-main';
        $json['repositories'][] = ['name' => 'site7-studio', 'type' => 'vcs', 'url' => '/var/www/html/repos/site7-studio'];
        $lock['packages'][] = ['name' => 'site7/studio', 'version' => 'dev-main', 'source' => ['type' => 'git', 'url' => '/var/www/html/repos/site7-studio', 'reference' => 'abc']];

        return [$json, $lock];
    }

    public function testAThemeUpdateKeepsTheSitesSite7Studio(): void
    {
        [$oldJson, $oldLock] = $this->theme();
        [$newJson, $newLock] = $this->theme(['nystudio107/craft-vite' => '^5.0']);
        $this->write("{$this->tmp}/baseline/files", $oldJson, $oldLock);
        $this->write("{$this->tmp}/new/files", $newJson, $newLock);
        $this->write("{$this->tmp}/site", ...$this->siteWithSite7($oldJson, $oldLock));

        [$report, $changed] = (new ThemeUpdater())->applyFiles("{$this->tmp}/baseline/files", "{$this->tmp}/new/files", "{$this->tmp}/site");

        $json = json_decode(file_get_contents("{$this->tmp}/site/composer.json"), true);
        $lock = json_decode(file_get_contents("{$this->tmp}/site/composer.lock"), true);
        // The Theme's change arrived ...
        $this->assertSame('^5.0', $json['require']['nystudio107/craft-vite'] ?? null);
        $this->assertContains('composer.json', $changed);
        $this->assertContains('file composer.json', $report['updated']);
        // ... and the site's own Site7 Studio stayed: require line, repository and locked version.
        $this->assertSame('dev-main', $json['require']['site7/studio'] ?? null);
        $this->assertContains('/var/www/html/repos/site7-studio', array_column($json['repositories'], 'url'));
        $this->assertContains('site7/studio', array_column($lock['packages'], 'name'));
        $this->assertSame(ComposerFiles::contentHash($json), $lock['content-hash']);
    }

    public function testComposerFilesTheSiteEditedAreKept(): void
    {
        [$oldJson, $oldLock] = $this->theme();
        [$newJson, $newLock] = $this->theme(['nystudio107/craft-vite' => '^5.0']);
        $this->write("{$this->tmp}/baseline/files", $oldJson, $oldLock);
        $this->write("{$this->tmp}/new/files", $newJson, $newLock);
        [$siteJson, $siteLock] = $this->siteWithSite7($oldJson, $oldLock);
        $siteJson['require']['vendor/own-plugin'] = '^1.0';
        $this->write("{$this->tmp}/site", $siteJson, $siteLock);
        $before = file_get_contents("{$this->tmp}/site/composer.json");

        [$report] = (new ThemeUpdater())->applyFiles("{$this->tmp}/baseline/files", "{$this->tmp}/new/files", "{$this->tmp}/site");

        $this->assertContains('file composer.json', $report['kept']);
        $this->assertSame($before, file_get_contents("{$this->tmp}/site/composer.json"));
    }

    public function testAPluginTheNewThemeDropsIsKeptWhileTheSiteHasIt(): void
    {
        [$oldJson, $oldLock] = $this->theme(['vendor/old-plugin' => '^1.0']);
        foreach ($oldLock['packages'] as &$entry) {
            if ($entry['name'] === 'vendor/old-plugin') {
                $entry['type'] = 'craft-plugin';
            }
        }
        unset($entry);
        [$newJson, $newLock] = $this->theme();
        $this->write("{$this->tmp}/baseline/files", $oldJson, $oldLock);
        $this->write("{$this->tmp}/new/files", $newJson, $newLock);
        $this->write("{$this->tmp}/site", ...$this->siteWithSite7($oldJson, $oldLock));

        [$report] = (new ThemeUpdater())->applyFiles("{$this->tmp}/baseline/files", "{$this->tmp}/new/files", "{$this->tmp}/site");

        $json = json_decode(file_get_contents("{$this->tmp}/site/composer.json"), true);
        $lock = json_decode(file_get_contents("{$this->tmp}/site/composer.lock"), true);
        $this->assertSame('^1.0', $json['require']['vendor/old-plugin'] ?? null);
        $this->assertContains('vendor/old-plugin', array_column($lock['packages'], 'name'));
        $this->assertSame('dev-main', $json['require']['site7/studio'] ?? null);
        $this->assertSame(ComposerFiles::contentHash($json), $lock['content-hash']);
        $this->assertNotEmpty(array_filter($report['notes'], fn($note) => str_contains($note, 'vendor/old-plugin')));
    }

    public function testASiteWithoutSite7StudioGetsTheThemesFilesUnchanged(): void
    {
        [$oldJson, $oldLock] = $this->theme();
        [$newJson, $newLock] = $this->theme(['nystudio107/craft-vite' => '^5.0']);
        $this->write("{$this->tmp}/baseline/files", $oldJson, $oldLock);
        $this->write("{$this->tmp}/new/files", $newJson, $newLock);
        $this->write("{$this->tmp}/site", $oldJson, $oldLock);

        (new ThemeUpdater())->applyFiles("{$this->tmp}/baseline/files", "{$this->tmp}/new/files", "{$this->tmp}/site");

        $json = json_decode(file_get_contents("{$this->tmp}/site/composer.json"), true);
        $this->assertArrayNotHasKey('site7/studio', $json['require']);
        $this->assertSame('^5.0', $json['require']['nystudio107/craft-vite'] ?? null);
    }
}
