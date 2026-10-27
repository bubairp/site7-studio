<?php

namespace site7\studio\tests\unit\services\support;

use Codeception\Test\Unit;
use site7\studio\services\support\StudioVersion;
use site7\studio\Site7Studio;

/**
 * Which Site7 Studio a package needs (docs/52 §5): the same rule as the
 * Commerce24 stand-in's gate (commerce24/tests/gate-test.php).
 */
class StudioVersionTest extends Unit
{
    protected \UnitTester $tester;

    public function testAMissingOrUnreadableVersionIsTheOldest(): void
    {
        $this->assertSame('0.0.0', StudioVersion::parse(null));
        $this->assertSame('0.0.0', StudioVersion::parse('dev-main'), 'Craft\'s version for a Git install is the branch');
        $this->assertSame('0.0.0', StudioVersion::parse('1.1'));
        $this->assertSame('1.1.0', StudioVersion::parse(' 1.1.0 '));
    }

    public function testAPackageWithoutAMinimumWorksEverywhere(): void
    {
        $this->assertTrue(StudioVersion::satisfies(null, '0.0.0'));
        $this->assertTrue(StudioVersion::satisfies('', '0.0.0'));
    }

    public function testTheMinimumIsComparedAsAVersion(): void
    {
        $this->assertFalse(StudioVersion::satisfies('1.1.0', '1.0.9'));
        $this->assertFalse(StudioVersion::satisfies('1.1.0', 'dev-main'));
        $this->assertTrue(StudioVersion::satisfies('1.1.0', '1.1.0'));
        $this->assertTrue(StudioVersion::satisfies('1.1.0', '1.10.0'), 'not compared as text');
    }

    public function testThisReleaseServesTheThemePackage(): void
    {
        $this->assertTrue(StudioVersion::satisfies(StudioVersion::THEME_PACKAGE), 'a Theme built on site7/theme installs with this plugin');
        $this->assertSame(Site7Studio::RELEASE, StudioVersion::parse(Site7Studio::RELEASE), 'RELEASE is MAJOR.MINOR.PATCH');
        $this->assertStringContainsString('needs Site7 Studio 9.0.0 or newer', StudioVersion::refusal('rp-craft-theme 2.0.0', '9.0.0'));
    }
}
