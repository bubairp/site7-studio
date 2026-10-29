<?php

namespace site7\studio\tests\unit\services\support;

use Codeception\Test\Unit;
use site7\studio\services\support\StudioVersion;
use site7\studio\services\support\ThemeVersion;

/**
 * Which Theme a design needs (docs/60 §5): a design laid out in frontend/src is
 * refused on a Theme whose build tooling only knows src/.
 */
class ThemeVersionTest extends Unit
{
    protected \UnitTester $tester;

    public function testADesignWithoutAMinimumWorksOnAnyTheme(): void
    {
        $this->assertTrue(ThemeVersion::satisfies(null, '1.0.0'));
        $this->assertTrue(ThemeVersion::satisfies('', '1.0.0'));
    }

    public function testTheMinimumIsComparedAsAVersion(): void
    {
        $this->assertFalse(ThemeVersion::satisfies('1.9.0', '1.8.0'));
        $this->assertTrue(ThemeVersion::satisfies('1.9.0', '1.9.0'));
        $this->assertTrue(ThemeVersion::satisfies('1.9.0', '1.10.0'), 'not compared as text');
        $this->assertTrue(ThemeVersion::satisfies('1.9.0', '2.0.0'));
    }

    public function testAThemeWithoutAReadableVersionIsTheOldest(): void
    {
        $this->assertFalse(ThemeVersion::satisfies('1.9.0', 'dev-main'));
        $this->assertFalse(ThemeVersion::satisfies('1.9.0', ''));
    }

    public function testTheLayoutMinimumIsAVersion(): void
    {
        $this->assertSame(ThemeVersion::DESIGN_FRONTEND_LAYOUT, StudioVersion::parse(ThemeVersion::DESIGN_FRONTEND_LAYOUT), 'MAJOR.MINOR.PATCH');
    }

    public function testThisReleaseChecksADesignsTheme(): void
    {
        $this->assertTrue(StudioVersion::satisfies(StudioVersion::DESIGN_THEME_GATE), 'a design with a minimum Theme installs with this plugin');
        $this->assertFalse(StudioVersion::satisfies(StudioVersion::DESIGN_THEME_GATE, '1.1.0'), 'and is never offered to the release before the check');
    }

    public function testTheRefusalSaysWhatToUpdate(): void
    {
        $message = ThemeVersion::refusal('Harbor', '1.9.0', '1.8.0');
        $this->assertStringContainsString('needs the Theme 1.9.0 or newer', $message);
        $this->assertStringContainsString('this site has 1.8.0', $message);
        $this->assertStringContainsString('Update the Theme first', $message);
    }
}
