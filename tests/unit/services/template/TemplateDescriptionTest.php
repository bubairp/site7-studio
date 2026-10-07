<?php

namespace site7\studio\tests\unit\services\template;

use Codeception\Test\Unit;
use site7\studio\services\template\TemplateBuilder;

class TemplateDescriptionTest extends Unit
{
    protected \UnitTester $tester;

    public function testItCountsTheSections(): void
    {
        $this->assertSame('The Home page, built from 5 sections.', TemplateBuilder::description('Home', 5));
        $this->assertSame('The Contact page, built from 1 section.', TemplateBuilder::description('Contact', 1));
        $this->assertSame('The Anup Payra page.', TemplateBuilder::description('Anup Payra', 0));
    }
}
