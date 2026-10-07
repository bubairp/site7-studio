<?php

namespace site7\studio\tests\unit\services\theme;

use Codeception\Test\Unit;
use site7\studio\services\theme\ThemeSchemaService;

class ThemeSchemaSectionsTest extends Unit
{
    protected \UnitTester $tester;

    private function schema(): array
    {
        return ['items' => [
            ['path' => 'sections.a', 'handle' => 'home', 'config' => []],
            ['path' => 'sections.b', 'handle' => 'blogs', 'config' => []],
            ['path' => 'entryTypes.c', 'handle' => 'blogs', 'config' => []],
            ['path' => 'fields.d', 'handle' => 'title', 'config' => []],
        ]];
    }

    public function testOptionalSectionsAreLeftOutButNotTheirEntryTypes(): void
    {
        $paths = array_column(ThemeSchemaService::withoutSections($this->schema(), ['blogs'])['items'], 'path');
        $this->assertSame(['sections.a', 'entryTypes.c', 'fields.d'], $paths);
    }

    public function testWithoutOptionalSectionsTheSchemaIsUnchanged(): void
    {
        $this->assertSame($this->schema(), ThemeSchemaService::withoutSections($this->schema(), []));
    }

    public function testAddingASectionTakesOnlyThatSection(): void
    {
        $paths = array_column(ThemeSchemaService::onlySections($this->schema(), ['blogs'])['items'], 'path');
        $this->assertSame(['sections.b'], $paths);
    }
}
