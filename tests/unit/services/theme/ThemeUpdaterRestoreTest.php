<?php

namespace site7\studio\tests\unit\services\theme;

use Codeception\Test\Unit;
use site7\studio\services\theme\ThemeUpdater;

/**
 * A failed Theme update puts the site's files back as they were (docs/43 #52):
 * updated files restored, added files removed, deleted files back.
 */
class ThemeUpdaterRestoreTest extends Unit
{
    protected \UnitTester $tester;

    private string $tmp;

    protected function _before()
    {
        $this->tmp = sys_get_temp_dir() . '/site7-theme-restore-' . uniqid();
        mkdir("{$this->tmp}/storage", 0777, true);
        \Yii::setAlias('@storage', "{$this->tmp}/storage");
        \Craft::$app = new class {
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

    private function put(string $path, string $content): void
    {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    public function testRestoreUndoesWhatTheUpdateWrote(): void
    {
        $root = "{$this->tmp}/site";
        // Installed version: a.twig, gone.twig; new version: a.twig, new.twig.
        $this->put("{$this->tmp}/baseline/templates/a.twig", 'old a');
        $this->put("{$this->tmp}/baseline/templates/gone.twig", 'gone');
        $this->put("{$this->tmp}/new/templates/a.twig", 'new a');
        $this->put("{$this->tmp}/new/templates/new.twig", 'new');
        $this->put("{$root}/templates/a.twig", 'old a');
        $this->put("{$root}/templates/gone.twig", 'gone');
        $this->put("{$root}/templates/own.twig", 'the site\'s own');
        $this->put("{$root}/composer.json", '{"require":{"site7/studio":"dev-main"}}');

        $updater = new ThemeUpdater();
        $backup = $updater->backupFiles("{$this->tmp}/baseline", "{$this->tmp}/new", $root);

        // What an update does before failing.
        $this->put("{$root}/templates/a.twig", 'new a');
        $this->put("{$root}/templates/new.twig", 'new');
        unlink("{$root}/templates/gone.twig");
        $this->put("{$root}/composer.json", '{"require":{}}');

        $this->assertSame(4, $updater->restoreFiles($backup, $root));
        $this->assertSame('old a', file_get_contents("{$root}/templates/a.twig"));
        $this->assertSame('gone', file_get_contents("{$root}/templates/gone.twig"));
        $this->assertFileDoesNotExist("{$root}/templates/new.twig");
        $this->assertSame('{"require":{"site7/studio":"dev-main"}}', file_get_contents("{$root}/composer.json"));
        // A file the Theme doesn't ship is never touched.
        $this->assertSame('the site\'s own', file_get_contents("{$root}/templates/own.twig"));
    }
}
