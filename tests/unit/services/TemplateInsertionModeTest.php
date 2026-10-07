<?php

namespace site7\studio\tests\unit\services;

use Codeception\Test\Unit;
use site7\studio\services\TemplateInsertionService;

/**
 * What the Content Browser's Insert brings for a Template (docs/44 §15).
 */
class TemplateInsertionModeTest extends Unit
{
    protected \UnitTester $tester;

    public function testGeneralPagesBringTheirContent(): void
    {
        $this->assertSame('content', TemplateInsertionService::modeFor(['sectionType' => 'single', 'uri' => '__home__']));
        $this->assertSame('content', TemplateInsertionService::modeFor(['sectionType' => 'single', 'uri' => 'contact']));
        $this->assertSame('content', TemplateInsertionService::modeFor(['sectionType' => 'channel', 'uri' => 'about-us']), 'a top-level page');
    }

    public function testDetailPagesBringLayoutOnly(): void
    {
        $this->assertSame('layout', TemplateInsertionService::modeFor(['sectionType' => 'channel', 'uri' => 'blogs/new-goal-4']));
        $this->assertSame('layout', TemplateInsertionService::modeFor(['sectionType' => 'structure', 'uri' => 'teams/asif-ali']));
    }

    public function testTemplateJsonCanSayWhich(): void
    {
        $this->assertSame('layout', TemplateInsertionService::modeFor(['sectionType' => 'single', 'uri' => 'contact', 'insert' => 'layout']));
        $this->assertSame('content', TemplateInsertionService::modeFor(['sectionType' => 'channel', 'uri' => 'blogs/x', 'insert' => 'content']));
        $this->assertSame('layout', TemplateInsertionService::modeFor(['sectionType' => 'channel', 'uri' => 'blogs/x', 'insert' => 'other']), 'unknown values are ignored');
    }
}
