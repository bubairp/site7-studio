<?php

namespace site7\studio\tests\unit\services\theme;

use Codeception\Test\Unit;
use site7\studio\services\theme\TailwindSafelist;

class TailwindSafelistTest extends Unit
{
    protected \UnitTester $tester;

    public function testItReadsClassAttributesAndSkipsClassesBuiltInTwig(): void
    {
        $twig = <<<'TWIG'
<div class="grid gap-4 md:grid-cols-2 lg:grid-cols-{{ columns }} {{ extra }}">
  <h2 class='text-2xl font-bold {% if dark %}text-white{% endif %}'>{{ title }}</h2>
  {# class="not-a-class" #}
  <span class="-mt-2 w-[42px] hover:bg-gray-100/50">x</span>
</div>
TWIG;
        $classes = TailwindSafelist::extractClasses($twig);

        foreach (['grid', 'gap-4', 'md:grid-cols-2', 'text-2xl', 'font-bold', 'text-white', '-mt-2', 'w-[42px]', 'hover:bg-gray-100/50'] as $class) {
            $this->assertContains($class, $classes);
        }
        $this->assertNotContains('lg:grid-cols-', $classes);
        $this->assertNotContains('not-a-class', $classes);
        foreach ($classes as $class) {
            $this->assertStringNotContainsString('{', $class);
        }
    }

    public function testItReadsQuotedClassesInsideTwigTags(): void
    {
        $classes = TailwindSafelist::extractClasses("{% set cls = 'p-4 md:p-8' %}<div class=\"{{ cls }}\">{{ dark ? 'bg-black text-white' : 'bg-white' }}</div>");

        $this->assertEqualsCanonicalizing(['bg-black', 'bg-white', 'md:p-8', 'p-4', 'text-white'], $classes);
    }

    public function testUtilityClassesComeOnlyFromClassAttributes(): void
    {
        $classes = TailwindSafelist::utilityClasses("{% set x = 'p-4' %}<div class=\"card md:flex px-6 lg:w-{{ n }}\"></div>");

        $this->assertSame(['md:flex', 'px-6'], $classes);
    }

    public function testTheSourceLineIsAddedOnceAfterTheLastSource(): void
    {
        $css = "@import 'tailwindcss';\n@import './theme.css';\n@source '../../../templates/**/*.twig';\n@source '../../js/**/*.js';\n.a{}\n";
        $once = TailwindSafelist::withSourceLine($css);

        $this->assertSame("@import 'tailwindcss';\n@import './theme.css';\n@source '../../../templates/**/*.twig';\n@source '../../js/**/*.js';\n" . TailwindSafelist::SOURCE_LINE . "\n.a{}\n", $once);
        $this->assertSame($once, TailwindSafelist::withSourceLine($once));
    }

    public function testWithoutSourceLinesItGoesAfterTheTailwindImport(): void
    {
        $this->assertSame("@import \"tailwindcss\";\n" . TailwindSafelist::SOURCE_LINE . "\n.a{}", TailwindSafelist::withSourceLine("@import \"tailwindcss\";\n.a{}"));
    }

    public function testTheListIsSortedUniqueAndRoundTrips(): void
    {
        $rendered = TailwindSafelist::render(['p-4', 'flex', 'p-4', 'md:flex']);

        $this->assertSame(['flex', 'md:flex', 'p-4'], TailwindSafelist::parseList($rendered));
        $this->assertSame($rendered, TailwindSafelist::render(TailwindSafelist::parseList($rendered)));
    }

    public function testMergeOnlyAddsAndIsIdempotent(): void
    {
        $root = sys_get_temp_dir() . '/site7-safelist-' . uniqid();
        mkdir("{$root}/frontend/src/css", 0777, true);
        mkdir("{$root}/frontend/node_modules/x", 0777, true);
        file_put_contents("{$root}/frontend/node_modules/x/tw.css", "@import 'tailwindcss';\n");
        file_put_contents("{$root}/frontend/src/css/app.css", "@import 'tailwindcss';\n@source '../../../templates/**/*.twig';\n");
        try {
            $first = TailwindSafelist::merge($root, ['p-4', 'flex']);
            $this->assertSame("{$root}/frontend/src/css/" . TailwindSafelist::FILE, str_replace('\\', '/', $first['path']));
            $this->assertEqualsCanonicalizing(['p-4', 'flex'], $first['added']);

            $second = TailwindSafelist::merge($root, ['flex', 'md:p-8']);
            $this->assertSame(['md:p-8'], $second['added']);
            $this->assertSame(['flex', 'md:p-8', 'p-4'], TailwindSafelist::parseList(file_get_contents($first['path'])));
            $this->assertSame(1, substr_count(file_get_contents("{$root}/frontend/src/css/app.css"), TailwindSafelist::SOURCE_LINE));

            $this->assertSame([], TailwindSafelist::merge($root, ['flex'])['added']);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testASiteWithoutTailwindIsLeftAlone(): void
    {
        $this->assertNull(TailwindSafelist::merge(sys_get_temp_dir() . '/site7-no-frontend-' . uniqid(), ['p-4']));
    }

    public function testBuiltSelectorsAreUnescaped(): void
    {
        $selectors = TailwindSafelist::selectorsFromCss('.md\:grid-cols-2{}.w-\[42px\]{}.\32 xl\:p-4{}.hover\:bg-gray-100\/50:hover{}');

        foreach (['md:grid-cols-2', 'w-[42px]', '2xl:p-4', 'hover:bg-gray-100/50'] as $class) {
            $this->assertArrayHasKey($class, $selectors);
        }
    }
}
