<?php

namespace site7\studio\tests\unit;

use Codeception\Test\Unit;
use site7\studio\Site7Studio;

class LibraryPathTest extends Unit
{
    protected \UnitTester $tester;

    private string $root;

    protected function _before(): void
    {
        $this->root = sys_get_temp_dir() . '/site7-library-test-' . uniqid();
        mkdir("{$this->root}/vendor/site7/studio", 0777, true);
        mkdir("{$this->root}/plugins/site7-studio", 0777, true);
        mkdir("{$this->root}/storage", 0777, true);
    }

    protected function _after(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAPluginComposerInstalledInVendorKeepsItsLibraryInStorage(): void
    {
        $this->assertSame(
            "{$this->root}/storage/site7-studio/packages",
            Site7Studio::defaultLibraryPath("{$this->root}/vendor/site7/studio", "{$this->root}/vendor", "{$this->root}/storage")
        );
    }

    public function testAPluginInItsOwnFolderKeepsItsLibraryThere(): void
    {
        $this->assertSame(
            realpath("{$this->root}/plugins/site7-studio") . '/packages',
            Site7Studio::defaultLibraryPath("{$this->root}/plugins/site7-studio", "{$this->root}/vendor", "{$this->root}/storage")
        );
    }

    public function testASymlinkedPathRepositoryCountsAsItsOwnFolder(): void
    {
        // rp-craft: vendor/site7/studio -> plugins/site7-studio
        rmdir("{$this->root}/vendor/site7/studio");
        symlink("{$this->root}/plugins/site7-studio", "{$this->root}/vendor/site7/studio");

        $this->assertSame(
            realpath("{$this->root}/plugins/site7-studio") . '/packages',
            Site7Studio::defaultLibraryPath("{$this->root}/vendor/site7/studio", "{$this->root}/vendor", "{$this->root}/storage")
        );
    }
}
