<?php

namespace site7\studio\tests\unit\services\theme;

use Codeception\Test\Unit;
use site7\studio\services\theme\ThemeSchemaService;

class ThemeSchemaServiceTest extends Unit
{
    protected \UnitTester $tester;

    private const SECTION = '11111111-1111-4111-8111-111111111111';
    private const ENTRY_TYPE = '22222222-2222-4222-8222-222222222222';
    private const FIELD = '33333333-3333-4333-8333-333333333333';
    private const VOLUME = '44444444-4444-4444-8444-444444444444';
    private const SITE = '55555555-5555-4555-8555-555555555555';

    private function index(): array
    {
        return [
            self::SECTION => 'sections.' . self::SECTION,
            self::ENTRY_TYPE => 'entryTypes.' . self::ENTRY_TYPE,
            self::FIELD => 'fields.' . self::FIELD,
            self::VOLUME => 'volumes.' . self::VOLUME,
            'fs:public' => 'fs.public',
        ];
    }

    public function testSectionReferencesItsEntryTypesButNotSites(): void
    {
        $section = ['entryTypes' => [['uid' => self::ENTRY_TYPE]], 'siteSettings' => [self::SITE => ['uriFormat' => '__home__']]];

        $this->assertSame(['entryTypes.' . self::ENTRY_TYPE], ThemeSchemaService::references('sections.' . self::SECTION, $section, $this->index()));
    }

    public function testPrefixedUidsAndFieldUidsAreReferences(): void
    {
        $field = ['type' => 'craft\fields\Entries', 'settings' => ['sources' => ['section:' . self::SECTION]], 'layout' => ['fieldUid' => self::FIELD]];

        $refs = ThemeSchemaService::references('fields.x', $field, $this->index());

        $this->assertEqualsCanonicalizing(['sections.' . self::SECTION, 'fields.' . self::FIELD], $refs);
    }

    public function testVolumeReferencesItsFilesystemByHandle(): void
    {
        $this->assertSame(['fs.public'], ThemeSchemaService::references('volumes.' . self::VOLUME, ['fs' => 'public', 'transformFs' => 'public'], $this->index()));
    }

    public function testAnItemIsNotItsOwnReference(): void
    {
        $this->assertSame([], ThemeSchemaService::references('fields.' . self::FIELD, ['note' => self::FIELD], $this->index()));
    }
}
