<?php

namespace site7\studio\tests\unit\services\theme;

use Codeception\Test\Unit;
use site7\studio\services\theme\ThemeInstaller;

/**
 * The Theme's composer.lock pins a Craft version; the install compares it
 * with the site's own to keep the site's Craft (docs/49 §2a).
 */
class ThemeLockedVersionTest extends Unit
{
    protected \UnitTester $tester;

    private function locked(string $lockJson, string $package): ?string
    {
        $file = tempnam(sys_get_temp_dir(), 'lock');
        file_put_contents($file, $lockJson);
        $method = new \ReflectionMethod(ThemeInstaller::class, 'lockedVersion');
        $method->setAccessible(true);
        try {
            return $method->invoke(null, $file, $package);
        } finally {
            unlink($file);
        }
    }

    public function testItReadsThePinnedVersion(): void
    {
        $lock = json_encode(['packages' => [
            ['name' => 'craftcms/ckeditor', 'version' => '4.2.0'],
            ['name' => 'craftcms/cms', 'version' => '5.10.8.1'],
        ]]);
        $this->assertSame('5.10.8.1', $this->locked($lock, 'craftcms/cms'));
    }

    public function testMissingPackageOrFile(): void
    {
        $this->assertNull($this->locked(json_encode(['packages' => []]), 'craftcms/cms'));
        $this->assertNull($this->locked('not json', 'craftcms/cms'));
    }
}
