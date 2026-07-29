<?php

namespace site7\studio\tests\unit\services;

use Codeception\Test\Unit;
use site7\studio\models\packages\PackageManifest;
use site7\studio\services\DependencyAnalyzer;

/**
 * Covers DependencyAnalyzer::buildContentItems()/buildFrontendItems() -
 * static, Craft-app-independent array shaping off an already-built
 * PackageManifest (Website Starter Kit System Phase 5). The graph-traversal
 * half of analyze() needs a live CraftResourceRegistry/Craft app and is
 * covered by live DDEV verification instead, matching this repo's existing
 * convention of only unit-testing the Craft-independent pieces.
 */
class DependencyAnalyzerTest extends Unit
{
    protected \UnitTester $tester;

    public function testBuildContentItemsDescribesPagesAndGlobals()
    {
        $manifest = new PackageManifest([
            'schemaVersion' => '1', 'type' => 'starter-kit', 'handle' => 'test', 'name' => 'Test', 'version' => '1.0.0',
            'pages' => [
                ['title' => 'Home', 'slug' => 'home', 'templateHandle' => 'home-template'],
            ],
            'globals' => [
                ['globalSetHandle' => 'footer', 'name' => 'Footer'],
            ],
        ]);

        $items = DependencyAnalyzer::buildContentItems($manifest);

        $this->assertSame([
            ['type' => 'page', 'key' => 'home', 'handle' => 'home-template', 'label' => 'Home'],
            ['type' => 'globalSet', 'key' => 'footer', 'handle' => 'footer', 'label' => 'Footer'],
        ], $items);
    }

    public function testBuildContentItemsIsEmptyWithNoPagesOrGlobals()
    {
        $manifest = new PackageManifest(['schemaVersion' => '1', 'type' => 'starter-kit', 'handle' => 'test', 'name' => 'Test', 'version' => '1.0.0']);

        $this->assertSame([], DependencyAnalyzer::buildContentItems($manifest));
    }

    public function testBuildFrontendItemsIncludesNpmInstallAndBuildWhenBothPresent()
    {
        $manifest = new PackageManifest([
            'schemaVersion' => '1', 'type' => 'starter-kit', 'handle' => 'test', 'name' => 'Test', 'version' => '1.0.0',
            'dependencies' => [
                'npmPackages' => [['name' => 'vite', 'version' => '^5.0.0', 'dev' => true]],
                'frontendTooling' => ['system' => 'vite', 'configFiles' => ['vite.config.mjs']],
            ],
        ]);

        $items = DependencyAnalyzer::buildFrontendItems($manifest);

        $this->assertCount(2, $items);
        $this->assertSame('npm-install', $items[0]['type']);
        $this->assertSame('build', $items[1]['type']);
        $this->assertSame('Run vite build', $items[1]['label']);
    }

    public function testBuildFrontendItemsIsEmptyWithNoFrontendCapture()
    {
        $manifest = new PackageManifest(['schemaVersion' => '1', 'type' => 'starter-kit', 'handle' => 'test', 'name' => 'Test', 'version' => '1.0.0']);

        $this->assertSame([], DependencyAnalyzer::buildFrontendItems($manifest));
    }

    public function testBuildFrontendItemsOmitsBuildStepWhenNoConfigFilesDetected()
    {
        $manifest = new PackageManifest([
            'schemaVersion' => '1', 'type' => 'starter-kit', 'handle' => 'test', 'name' => 'Test', 'version' => '1.0.0',
            'dependencies' => [
                'npmPackages' => [['name' => 'lodash', 'version' => '^4.0.0', 'dev' => false]],
                'frontendTooling' => ['system' => null, 'configFiles' => []],
            ],
        ]);

        $items = DependencyAnalyzer::buildFrontendItems($manifest);

        $this->assertCount(1, $items);
        $this->assertSame('npm-install', $items[0]['type']);
    }
}
