<?php

namespace site7\studio\tests\unit\services\template;

use Codeception\Test\Unit;
use site7\studio\services\sitekit\SiteKitContent;
use site7\studio\services\template\TemplateBuilder;

class TemplatePackageTest extends Unit
{
    protected \UnitTester $tester;

    public function testASingleIsNamedAfterItsSection(): void
    {
        $this->assertSame('template-contact', TemplateBuilder::handleFrom('contact', 'single', 'contact'));
    }

    public function testOtherPagesAreNamedAfterSectionAndSlug(): void
    {
        $this->assertSame('template-standard-pages-about-us', TemplateBuilder::handleFrom('standardPages', 'channel', 'about-us'));
    }

    public function testARelationWithOneEndOutsideTheExportIsALink(): void
    {
        $export = [10 => true, 11 => true];
        $live = [10 => true, 11 => true, 20 => true];

        $this->assertTrue(SiteKitContent::isLink(['sourceId' => 11, 'targetId' => 20], $export, $live));
        $this->assertTrue(SiteKitContent::isLink(['sourceId' => 20, 'targetId' => 10], $export, $live));
    }

    public function testRelationsInsideTheExportOrToLeftoversAreNotLinks(): void
    {
        $export = [10 => true, 11 => true];
        $live = [10 => true, 11 => true, 20 => true];

        $this->assertFalse(SiteKitContent::isLink(['sourceId' => 10, 'targetId' => 11], $export, $live), 'travels in relations.json');
        $this->assertFalse(SiteKitContent::isLink(['sourceId' => 10, 'targetId' => 99], $export, $live), 'target is not live content');
        $this->assertFalse(SiteKitContent::isLink(['sourceId' => 20, 'targetId' => 21], $export, $live), 'not about this export');
    }
}
