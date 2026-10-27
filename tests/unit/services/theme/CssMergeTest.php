<?php

namespace site7\studio\tests\unit\services\theme;

use Codeception\Test\Unit;
use site7\studio\services\theme\CssMerge;

/**
 * A site's additions to app.css carried into the Theme's new one (docs/53 §4).
 */
class CssMergeTest extends Unit
{
    protected \UnitTester $tester;

    private const OLD = "@import 'tailwindcss';\n@import './theme.css';\n@import './components/sections.css';\n\n@source '../../templates/**/*.twig';\n";
    private const NEW = "@import 'tailwindcss';\n@import '@site7-theme/css/site7.css';\n\n/* =====\n   THIS SITE\n   ===== */\n\n/* =====\n   TAILWIND SOURCES\n   ===== */\n@source '../../../templates/**/*.twig';\n";

    public function testRulesAddedAtTheEndAreFound(): void
    {
        $live = self::OLD . "\n/* my rule */\n.footer-note { letter-spacing: .02em; }\n";
        $this->assertSame(['/* my rule */', '.footer-note { letter-spacing: .02em; }'], CssMerge::additions(self::OLD, $live));
    }

    public function testRulesAddedInTheMiddleAreFoundToo(): void
    {
        $live = str_replace("\n\n@source", "\n.mid { color: red; }\n\n@source", self::OLD);
        $this->assertSame(['.mid { color: red; }'], CssMerge::additions(self::OLD, $live));
    }

    public function testAChangedOrRemovedLineCantBeMerged(): void
    {
        $this->assertNull(CssMerge::additions(self::OLD, str_replace("@import './theme.css';\n", '', self::OLD)), 'a removed import');
        $this->assertNull(CssMerge::additions(self::OLD, str_replace('sections.css', 'my-sections.css', self::OLD)), 'a changed import');
    }

    public function testAnAddedImportCantBeMerged(): void
    {
        $this->assertNull(CssMerge::additions(self::OLD, self::OLD . "@import './my.css';\n"), '@import must stay above the rules');
    }

    public function testNoAdditionsIsAnEmptyList(): void
    {
        $this->assertSame([], CssMerge::additions(self::OLD, str_replace("\n", "\r\n", self::OLD)), 'line endings alone are no change');
    }

    public function testAdditionsGoAboveTheTailwindSources(): void
    {
        $merged = CssMerge::merge(self::NEW, ['.footer-note { letter-spacing: .02em; }']);

        $this->assertStringContainsString("@import '@site7-theme/css/site7.css';", $merged);
        $this->assertStringNotContainsString("./theme.css", $merged);
        $this->assertLessThan(strpos($merged, 'TAILWIND SOURCES'), strpos($merged, '.footer-note'));
        $this->assertGreaterThan(strpos($merged, 'THIS SITE'), strpos($merged, CssMerge::HEADING));
        $this->assertStringEndsWith("@source '../../../templates/**/*.twig';\n", $merged);
    }

    public function testWithoutASourcesSectionTheyGoAtTheEnd(): void
    {
        $this->assertStringEndsWith(CssMerge::HEADING . "\n.x {}\n\n", CssMerge::merge("@import 'tailwindcss';\n", ['.x {}']));
        $this->assertSame(self::NEW, CssMerge::merge(self::NEW, []));
    }
}
