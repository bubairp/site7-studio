<?php

namespace site7\studio\tests\unit\services\installation;

use Codeception\Test\Unit;
use site7\studio\models\installation\InstallationStep;
use site7\studio\services\installation\InstallationPlanner;

/**
 * Covers InstallationPlanner::planCraftResources()/planContent()/
 * planFrontend() - static, Craft-app-independent pure array shaping off an
 * already-built Blueprint fragment (Website Starter Kit System Phase 6).
 * planPlugins() needs a live ComposerDependencyScanner/Craft app (checking
 * the target's own composer.json) and is covered by live DDEV verification
 * instead, matching this repo's established convention.
 */
class InstallationPlannerTest extends Unit
{
    protected \UnitTester $tester;

    public function testPlanCraftResourcesCoversAllFourKinds()
    {
        $steps = InstallationPlanner::planCraftResources([
            'assetVolumes' => [['handle' => 'images', 'name' => 'Images']],
            'categoryGroups' => [['handle' => 'blogCategories', 'name' => 'Blog Categories']],
            'tagGroups' => [['handle' => 'topics', 'name' => 'Topics']],
            'craftSections' => [['handle' => 'blog', 'name' => 'Blog']],
        ]);

        $this->assertCount(4, $steps);
        $types = array_map(fn(InstallationStep $s) => $s->type, $steps);
        $this->assertSame([
            InstallationStep::TYPE_CRAFT_RESOURCE,
            InstallationStep::TYPE_CRAFT_RESOURCE,
            InstallationStep::TYPE_CRAFT_RESOURCE,
            InstallationStep::TYPE_CRAFT_RESOURCE,
        ], $types);
        $this->assertSame('assetVolume:images', $steps[0]->key);
        $this->assertSame('categoryGroup:blogCategories', $steps[1]->key);
        $this->assertSame('tagGroup:topics', $steps[2]->key);
        $this->assertSame('craftSection:blog', $steps[3]->key);
    }

    public function testPlanCraftResourcesIsEmptyWithNoResources()
    {
        $this->assertSame([], InstallationPlanner::planCraftResources([]));
    }

    public function testPlanContentProducesOneStepKeyedByPackageHandle()
    {
        $steps = InstallationPlanner::planContent('my-starter-kit');

        $this->assertCount(1, $steps);
        $this->assertSame(InstallationStep::TYPE_CONTENT, $steps[0]->type);
        $this->assertSame('my-starter-kit', $steps[0]->key);
        $this->assertSame('my-starter-kit', $steps[0]->payload['packageHandle']);
    }

    public function testPlanFrontendIncludesBothStepsWhenPackageHasFrontendDirectory()
    {
        $tempDir = sys_get_temp_dir() . '/site7_planner_test_' . uniqid();
        mkdir($tempDir . '/frontend', 0777, true);

        try {
            $steps = InstallationPlanner::planFrontend([
                'npmPackages' => [['name' => 'vite', 'version' => '^5.0.0', 'dev' => true]],
                'frontendTooling' => ['system' => 'vite', 'configFiles' => ['vite.config.mjs']],
            ], $tempDir);

            $this->assertCount(2, $steps);
            $this->assertSame(InstallationStep::TYPE_FRONTEND, $steps[0]->type);
            $this->assertSame(InstallationStep::TYPE_NPM, $steps[1]->type);
        } finally {
            rmdir($tempDir . '/frontend');
            rmdir($tempDir);
        }
    }

    public function testPlanFrontendOmitsConfigStepWhenPackageHasNoFrontendDirectory()
    {
        $tempDir = sys_get_temp_dir() . '/site7_planner_test_' . uniqid();
        mkdir($tempDir);

        try {
            $steps = InstallationPlanner::planFrontend([
                'npmPackages' => [['name' => 'vite', 'version' => '^5.0.0', 'dev' => true]],
                'frontendTooling' => ['system' => 'vite', 'configFiles' => ['vite.config.mjs']],
            ], $tempDir);

            // No frontend/ directory inside the package despite configFiles
            // being listed - defensive: only the npm step should appear.
            $this->assertCount(1, $steps);
            $this->assertSame(InstallationStep::TYPE_NPM, $steps[0]->type);
        } finally {
            rmdir($tempDir);
        }
    }

    public function testPlanFrontendIsEmptyWithNoFrontendCapture()
    {
        $this->assertSame([], InstallationPlanner::planFrontend([], sys_get_temp_dir()));
    }
}
