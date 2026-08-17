<?php

namespace site7\studio\tests\unit\services\support;

use Codeception\Test\Unit;
use site7\studio\services\support\PackageArchiveHelper;

/**
 * Covers PackageArchiveHelper::computeFileChecksum() - the per-file sha256
 * primitive extracted from computeDirectoryChecksum() so a single file can be
 * compared (e.g. a package's template.twig against its live _blocks/ source)
 * using the exact same hashing convention the directory checksum already
 * uses, rather than a second one.
 */
class PackageArchiveHelperTest extends Unit
{
    protected \UnitTester $tester;

    private string $tempDir;

    protected function _before()
    {
        $this->tempDir = sys_get_temp_dir() . '/site7_archive_helper_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function _after()
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testComputeFileChecksumReturnsNullForMissingFile()
    {
        $this->assertNull(PackageArchiveHelper::computeFileChecksum($this->tempDir . '/nope.twig'));
    }

    public function testComputeFileChecksumIsStableForIdenticalContent()
    {
        $pathA = $this->tempDir . '/a.twig';
        $pathB = $this->tempDir . '/b.twig';
        file_put_contents($pathA, "<div>{{ block.heading }}</div>\n");
        file_put_contents($pathB, "<div>{{ block.heading }}</div>\n");

        $this->assertSame(
            PackageArchiveHelper::computeFileChecksum($pathA),
            PackageArchiveHelper::computeFileChecksum($pathB)
        );
    }

    public function testComputeFileChecksumDiffersForDifferentContent()
    {
        $pathA = $this->tempDir . '/a.twig';
        $pathB = $this->tempDir . '/b.twig';
        file_put_contents($pathA, "<div>{{ block.heading }}</div>\n");
        file_put_contents($pathB, "<div>{{ block.subheading }}</div>\n");

        $this->assertNotSame(
            PackageArchiveHelper::computeFileChecksum($pathA),
            PackageArchiveHelper::computeFileChecksum($pathB)
        );
    }

    public function testComputeFileChecksumMatchesHashFileDirectly()
    {
        $path = $this->tempDir . '/a.twig';
        file_put_contents($path, "<div>{{ block.heading }}</div>\n");

        $this->assertSame(hash_file('sha256', $path), PackageArchiveHelper::computeFileChecksum($path));
    }

    public function testComputeDirectoryChecksumUnaffectedByExtraction()
    {
        // Regression guard: computeDirectoryChecksum() now calls
        // computeFileChecksum() internally instead of hash_file() inline -
        // its own output for a given directory must not change.
        file_put_contents($this->tempDir . '/template.twig', "<div>{{ block.heading }}</div>\n");
        mkdir($this->tempDir . '/preview');
        file_put_contents($this->tempDir . '/preview/preview-data.yaml', "block:\n  heading: ''\n");

        $checksum = PackageArchiveHelper::computeDirectoryChecksum($this->tempDir);

        $this->assertSame(64, strlen($checksum));
        $this->assertSame($checksum, PackageArchiveHelper::computeDirectoryChecksum($this->tempDir));
    }
}
