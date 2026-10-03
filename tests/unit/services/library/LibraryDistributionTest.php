<?php

namespace site7\studio\tests\unit\services\library;

use Codeception\Test\Unit;
use site7\studio\services\library\LibraryDistribution;

class LibraryDistributionTest extends Unit
{
    protected \UnitTester $tester;

    private function catalog(): array
    {
        return [
            'kit' => ['handle' => 'kit', 'requires' => ['themes' => ['theme'], 'templates' => ['template-home', 'template-contact']]],
            'theme' => ['handle' => 'theme', 'requires' => []],
            'template-home' => ['handle' => 'template-home', 'requires' => ['themes' => ['theme'], 'sections' => ['hero', 'faq']]],
            'template-contact' => ['handle' => 'template-contact', 'requires' => ['themes' => ['theme'], 'sections' => ['hero']]],
            'hero' => ['handle' => 'hero'],
            'faq' => ['handle' => 'faq'],
            'unrelated' => ['handle' => 'unrelated'],
        ];
    }

    public function testAKitNeedsItsThemeTemplatesAndTheirBlocksOnce(): void
    {
        $closure = LibraryDistribution::closure('kit', $this->catalog());

        $this->assertEqualsCanonicalizing(['kit', 'theme', 'template-home', 'template-contact', 'hero', 'faq'], $closure['handles']);
        $this->assertSame([], $closure['missing']);
    }

    public function testRequirementsMissingFromTheCatalogAreReported(): void
    {
        $catalog = $this->catalog();
        unset($catalog['faq']);

        $this->assertSame(['faq'], LibraryDistribution::closure('kit', $catalog)['missing']);
    }

    public function testRequiresKeysThatAreNotPackagesAreIgnored(): void
    {
        $catalog = ['page' => ['handle' => 'page', 'requires' => ['craftVersion' => ['5.10.8.1'], 'sections' => ['hero']]], 'hero' => ['handle' => 'hero']];

        $this->assertSame(['page', 'hero'], LibraryDistribution::closure('page', $catalog)['handles']);
    }
}
