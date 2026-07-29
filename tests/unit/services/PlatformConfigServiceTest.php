<?php

namespace site7\studio\tests\unit\services;

use Codeception\Test\Unit;
use site7\studio\services\PlatformConfigService;

/**
 * Covers PlatformConfigService's pure signal-word matching (Website Starter
 * Kit System Phase 3, replacing ResourceClassifierService's former private
 * placeholder heuristic) - static and Craft-app-independent, so no Craft
 * bootstrap is needed here.
 */
class PlatformConfigServiceTest extends Unit
{
    protected \UnitTester $tester;

    public function testThemeSignalWordsMatchTheThemeCategory()
    {
        $this->assertSame(PlatformConfigService::CATEGORY_THEME, PlatformConfigService::categoryFor('theme'));
        $this->assertSame(PlatformConfigService::CATEGORY_THEME, PlatformConfigService::categoryFor('colorPalette'));
        $this->assertSame(PlatformConfigService::CATEGORY_THEME, PlatformConfigService::categoryFor('colorLibrary'));
    }

    public function testTypographySignalWordMatchesTheTypographyCategory()
    {
        $this->assertSame(PlatformConfigService::CATEGORY_TYPOGRAPHY, PlatformConfigService::categoryFor('typographyPreset'));
    }

    public function testSpacingSignalWordsMatchTheSpacingCategory()
    {
        $this->assertSame(PlatformConfigService::CATEGORY_SPACING, PlatformConfigService::categoryFor('spacingScale'));
        $this->assertSame(PlatformConfigService::CATEGORY_SPACING, PlatformConfigService::categoryFor('containerWidth'));
    }

    public function testCustomCodeSignalWordsMatchTheCustomCodeCategory()
    {
        $this->assertSame(PlatformConfigService::CATEGORY_CUSTOM_CODE, PlatformConfigService::categoryFor('codeCss'));
        $this->assertSame(PlatformConfigService::CATEGORY_CUSTOM_CODE, PlatformConfigService::categoryFor('codeJs'));
    }

    public function testAnimationSignalWordMatchesTheAnimationCategory()
    {
        $this->assertSame(PlatformConfigService::CATEGORY_ANIMATION, PlatformConfigService::categoryFor('animationPreset'));
    }

    public function testMatchingIsCaseInsensitive()
    {
        $this->assertSame(PlatformConfigService::CATEGORY_THEME, PlatformConfigService::categoryFor('THEME'));
    }

    public function testOrdinaryFieldHandleMatchesNoCategory()
    {
        $this->assertNull(PlatformConfigService::categoryFor('heading'));
        $this->assertFalse(PlatformConfigService::isPlatformConfigField('heading'));
    }

    public function testIsPlatformConfigFieldMirrorsCategoryFor()
    {
        $this->assertTrue(PlatformConfigService::isPlatformConfigField('codeCss'));
    }

    public function testDescribeReturnsHandleAndCategory()
    {
        $this->assertSame(['handle' => 'codeCss', 'category' => PlatformConfigService::CATEGORY_CUSTOM_CODE], PlatformConfigService::describe('codeCss'));
        $this->assertNull(PlatformConfigService::describe('heading'));
    }
}
