<?php

namespace site7\studio\tests\unit\services\import;

use Codeception\Test\Unit;
use site7\studio\services\import\SectionSchemaService;

/**
 * Dependency discovery for format v2 - what install() must create first.
 */
class SectionSchemaServiceTest extends Unit
{
    protected \UnitTester $tester;

    public function testEntryTypeLayoutReferencesItsFields(): void
    {
        $config = ['handle' => 'gallery', 'fieldLayouts' => ['lay-1' => ['tabs' => [['elements' => [
            ['type' => 'craft\fieldlayoutelements\CustomField', 'fieldUid' => 'f-perrow', 'handle' => 'galleryPerRow'],
            ['type' => 'craft\fieldlayoutelements\CustomField', 'fieldUid' => 'f-images'],
            ['type' => 'craft\fieldlayoutelements\Heading', 'heading' => 'x'],
        ]]]]]];

        $this->assertSame([['fields', 'f-perrow'], ['fields', 'f-images']], SectionSchemaService::references('entryTypes', $config));
    }

    public function testMatrixFieldReferencesItsEntryTypesPackedOrNot(): void
    {
        $packed = ['type' => 'craft\fields\Matrix', 'settings' => ['entryTypes' => [
            ['__assoc__' => [['uid', 'et-button'], ['group', 'General']]],
            ['uid' => 'et-link'],
            'et-plain',
        ]]];

        $this->assertSame(
            [['entryTypes', 'et-button'], ['entryTypes', 'et-link'], ['entryTypes', 'et-plain']],
            SectionSchemaService::references('fields', $packed)
        );
    }

    public function testNonMatrixFieldEntryTypesSettingIsNotAReference(): void
    {
        $this->assertSame([], SectionSchemaService::references('fields', ['type' => 'craft\fields\Entries', 'settings' => ['entryTypes' => ['x']]]));
    }
}
