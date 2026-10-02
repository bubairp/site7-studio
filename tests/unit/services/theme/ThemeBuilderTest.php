<?php

namespace site7\studio\tests\unit\services\theme;

use Codeception\Test\Unit;
use site7\studio\services\theme\ThemeBuilder;

class ThemeBuilderTest extends Unit
{
    protected \UnitTester $tester;

    private const REVIEWS = '1e089da8-971a-4f86-adc3-aa8188d2a4cf';
    private const CLOSED = '99387a88-325c-40d7-896a-18db1aea0b01';

    public function testGuestSubmissionSectionsAreVisitorContent(): void
    {
        // As stored in project config: packed associative arrays.
        $settings = ['sections' => ['__assoc__' => [
            [self::REVIEWS, ['__assoc__' => [['sectionUid', self::REVIEWS], ['allowGuestSubmissions', '1']]]],
            [self::CLOSED, ['__assoc__' => [['sectionUid', self::CLOSED], ['allowGuestSubmissions', '']]]],
        ]]];

        $this->assertSame([self::REVIEWS => true], ThemeBuilder::visitorSectionUids($settings));
    }

    public function testNoGuestEntriesMeansNoVisitorContent(): void
    {
        $this->assertSame([], ThemeBuilder::visitorSectionUids([]));
    }
}
