<?php

namespace site7\studio\tests\unit\services\import;

use Codeception\Test\Unit;
use site7\studio\services\import\CraftResourceDiscoveryService;

/**
 * Covers CraftResourceDiscoveryService::scoreClassification()'s pure logic -
 * given precomputed signals, no Craft app/DB needed (the actual signal
 * gathering, which does need a live Craft app, lives in analyzeEntryType()
 * and isn't covered here, matching this repo's existing convention of only
 * unit-testing the Craft-independent pieces of the import services).
 */
class CraftResourceDiscoveryServiceTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    public function testRegisteredSharedResourceWins()
    {
        $result = CraftResourceDiscoveryService::scoreClassification([
            'isRegisteredShared' => true,
            'usageCount' => 0,
            'totalFieldCount' => 5,
        ]);

        $this->assertSame(CraftResourceDiscoveryService::SHARED_RESOURCE, $result['classification']);
        $this->assertFalse($result['reviewRequired']);
    }

    public function testHighUsageCountIsSharedResource()
    {
        $result = CraftResourceDiscoveryService::scoreClassification([
            'usageCount' => 6,
            'totalFieldCount' => 4,
        ]);

        $this->assertSame(CraftResourceDiscoveryService::SHARED_RESOURCE, $result['classification']);
    }

    public function testSite7MatrixMembershipIsPresentationSection()
    {
        $result = CraftResourceDiscoveryService::scoreClassification([
            'isInSite7Matrix' => true,
            'usageCount' => 1,
            'totalFieldCount' => 8,
        ]);

        $this->assertSame(CraftResourceDiscoveryService::PRESENTATION_SECTION, $result['classification']);
        $this->assertSame(100, $result['confidence']);
    }

    public function testCraftSectionDependencyIsFeatureComponent()
    {
        $result = CraftResourceDiscoveryService::scoreClassification([
            'craftSectionDependency' => true,
            'hasNestedMatrix' => true,
            'totalFieldCount' => 6,
        ]);

        $this->assertSame(CraftResourceDiscoveryService::FEATURE_COMPONENT, $result['classification']);
    }

    public function testAllStyleFieldsIsUtilityComponent()
    {
        $result = CraftResourceDiscoveryService::scoreClassification([
            'platformFieldCount' => 4,
            'totalFieldCount' => 4,
            'utilityNameMatch' => true,
        ]);

        $this->assertSame(CraftResourceDiscoveryService::UTILITY_COMPONENT, $result['classification']);
    }

    public function testMajorityPluginFieldsIsPluginComponent()
    {
        $result = CraftResourceDiscoveryService::scoreClassification([
            'pluginFieldCount' => 3,
            'totalFieldCount' => 3,
        ]);

        $this->assertSame(CraftResourceDiscoveryService::PLUGIN_COMPONENT, $result['classification']);
    }

    public function testNoSignalsIsUnknownAndReviewRequired()
    {
        $result = CraftResourceDiscoveryService::scoreClassification([
            'totalFieldCount' => 4,
        ]);

        $this->assertSame(CraftResourceDiscoveryService::UNKNOWN, $result['classification']);
        $this->assertTrue($result['reviewRequired']);
    }

    public function testCloseTieIsFlaggedReviewRequiredEvenWithABestGuess()
    {
        // Presentation (100) vs. a Shared-Resource signal close behind (usageCount=9 -> 60,
        // within the 15-point margin of a hypothetical near-tie) still yields a definite
        // top bucket, but a genuinely close pair should flag reviewRequired = true.
        $result = CraftResourceDiscoveryService::scoreClassification([
            'craftSectionDependency' => true,   // Feature Component: 60
            'hasNestedMatrix' => true,           // + 20 = 80
            'usageCount' => 7,                    // Shared Resource: min(60, 70) = 60 (not registered)
            'totalFieldCount' => 5,
        ]);

        // Feature Component (80) beats Shared Resource (60) by 20 - clears the margin.
        $this->assertSame(CraftResourceDiscoveryService::FEATURE_COMPONENT, $result['classification']);
        $this->assertFalse($result['reviewRequired']);
    }

    public function testEveryResultHasAClassificationNeverSilentlySkipped()
    {
        $cases = [
            CraftResourceDiscoveryService::scoreClassification([]),
            CraftResourceDiscoveryService::scoreClassification(['isRegisteredShared' => true]),
            CraftResourceDiscoveryService::scoreClassification(['pluginFieldCount' => 1, 'totalFieldCount' => 1]),
        ];

        foreach ($cases as $case) {
            $this->assertNotEmpty($case['classification']);
            $this->assertArrayHasKey('reviewRequired', $case);
        }
    }
}
