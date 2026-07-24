<?php

namespace site7\studio\tests\unit\services\import;

use Codeception\Test\Unit;
use site7\studio\services\import\ResourceClassifierService;

/**
 * Covers classifyField()'s pure logic - the parts that take a described
 * field + precomputed context and don't touch Craft::$app (fan-out/package-map/
 * registry lookups are computed by classifyFieldLayout(), not classifyField()
 * itself, and passed in via $context here). No Craft bootstrap needed.
 */
class ResourceClassifierServiceTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    private ResourceClassifierService $classifier;

    protected function _before()
    {
        $this->classifier = new ResourceClassifierService();
    }

    public function testAlreadyRegisteredIsSharedResource()
    {
        $result = $this->classifier->classifyField(
            ['handle' => 'blockStyle', 'name' => 'Block Style', 'type' => 'Matrix', 'supported' => false],
            ['isRegisteredShared' => true]
        );

        $this->assertSame(ResourceClassifierService::SHARED_RESOURCE, $result['classification']);
        $this->assertSame('reference', $result['action']);
    }

    public function testAssetsFieldIsSharedResource()
    {
        $result = $this->classifier->classifyField(
            ['handle' => 'heroImage', 'name' => 'Hero Image', 'type' => 'Assets', 'supported' => true],
            []
        );

        $this->assertSame(ResourceClassifierService::SHARED_RESOURCE, $result['classification']);
    }

    public function testCategoriesFieldClassIsSharedResource()
    {
        $result = $this->classifier->classifyField(
            ['handle' => 'topics', 'name' => 'Topics', 'type' => 'PlainText', 'supported' => false, 'fieldClass' => \craft\fields\Categories::class],
            []
        );

        $this->assertSame(ResourceClassifierService::SHARED_RESOURCE, $result['classification']);
    }

    public function testMatchingInstalledPackageIsPackageResource()
    {
        $result = $this->classifier->classifyField(
            ['handle' => 'headingContent', 'name' => 'Heading Content', 'type' => 'PlainText', 'supported' => false],
            ['packageHandle' => 'heading-content']
        );

        $this->assertSame(ResourceClassifierService::PACKAGE_RESOURCE, $result['classification']);
    }

    public function testThirdPartyPluginNamespaceIsPluginDependency()
    {
        $result = $this->classifier->classifyField(
            ['handle' => 'seoData', 'name' => 'SEO', 'type' => 'PlainText', 'supported' => false, 'fieldClass' => 'ether\\seo\\fields\\SeoField'],
            []
        );

        $this->assertSame(ResourceClassifierService::PLUGIN_DEPENDENCY, $result['classification']);
        $this->assertSame('report-missing-plugin', $result['action']);
        $this->assertSame('ether/seo', $result['requiredPlugin']);
    }

    public function testCraftCoreFieldIsNotPluginDependency()
    {
        $result = $this->classifier->classifyField(
            ['handle' => 'summary', 'name' => 'Summary', 'type' => 'PlainText', 'supported' => true, 'fieldClass' => \craft\fields\PlainText::class],
            []
        );

        $this->assertNotSame(ResourceClassifierService::PLUGIN_DEPENDENCY, $result['classification']);
    }

    public function testHighFanOutIsSharedResource()
    {
        $result = $this->classifier->classifyField(
            ['handle' => 'button', 'name' => 'Button', 'type' => 'PlainText', 'supported' => false, 'fieldClass' => \craft\fields\PlainText::class],
            ['fanOut' => 9]
        );

        $this->assertSame(ResourceClassifierService::SHARED_RESOURCE, $result['classification']);
    }

    public function testLowFanOutIsNotSharedResource()
    {
        $result = $this->classifier->classifyField(
            ['handle' => 'oneOffField', 'name' => 'One Off', 'type' => 'PlainText', 'supported' => true, 'fieldClass' => \craft\fields\PlainText::class],
            ['fanOut' => 1]
        );

        $this->assertNotSame(ResourceClassifierService::SHARED_RESOURCE, $result['classification']);
    }

    public function testPlatformSignalHandleIsPlatformConfiguration()
    {
        $result = $this->classifier->classifyField(
            ['handle' => 'codeCss', 'name' => 'Additional CSS', 'type' => 'PlainText', 'supported' => false, 'fieldClass' => \craft\fields\PlainText::class],
            ['fanOut' => 1]
        );

        $this->assertSame(ResourceClassifierService::PLATFORM_CONFIGURATION, $result['classification']);
    }

    public function testSupportedFieldWithNoOtherSignalIsFeatureResource()
    {
        $result = $this->classifier->classifyField(
            ['handle' => 'heading', 'name' => 'Heading', 'type' => 'PlainText', 'supported' => true, 'fieldClass' => \craft\fields\PlainText::class],
            ['fanOut' => 1]
        );

        $this->assertSame(ResourceClassifierService::FEATURE_RESOURCE, $result['classification']);
        $this->assertSame('import', $result['action']);
    }

    public function testUnsupportedFieldWithNoSignalIsUnknownResource()
    {
        $result = $this->classifier->classifyField(
            ['handle' => 'weirdField', 'name' => 'Weird', 'type' => 'SomeExoticType', 'supported' => false, 'fieldClass' => \craft\fields\PlainText::class],
            ['fanOut' => 1]
        );

        $this->assertSame(ResourceClassifierService::UNKNOWN_RESOURCE, $result['classification']);
        $this->assertSame('report-dependency', $result['action']);
    }

    public function testEveryClassificationHasAnAction()
    {
        $cases = [
            $this->classifier->classifyField(['handle' => 'a', 'type' => 'Assets', 'supported' => true], []),
            $this->classifier->classifyField(['handle' => 'b', 'type' => 'PlainText', 'supported' => true, 'fieldClass' => \craft\fields\PlainText::class], ['fanOut' => 1]),
            $this->classifier->classifyField(['handle' => 'c', 'type' => 'PlainText', 'supported' => false, 'fieldClass' => \craft\fields\PlainText::class], ['fanOut' => 1]),
        ];

        foreach ($cases as $case) {
            $this->assertNotEmpty($case['action'], 'Every classification must resolve to a non-empty action - never a silent skip.');
        }
    }
}
