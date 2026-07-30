<?php

namespace site7\studio\tests\unit\services\synchronization;

use Codeception\Test\Unit;
use site7\studio\models\synchronization\Conflict;
use site7\studio\services\synchronization\SynchronizationPlanner;

/**
 * Covers SynchronizationPlanner's diff/conflict-detection logic (Website
 * Starter Kit System Phase 8) using fake scanners (duck-typed - the planner
 * only ever calls ->findByHandle() on whatever's injected) rather than a
 * live Craft app, the same rationale InstallationPlannerTest already
 * documents for its own Craft-independent methods.
 */
class SynchronizationPlannerTest extends Unit
{
    protected \UnitTester $tester;

    private function fakeScanner(array $liveByHandle): object
    {
        return new class ($liveByHandle) {
            public function __construct(private readonly array $liveByHandle)
            {
            }

            public function findByHandle(string $handle): ?object
            {
                if (!isset($this->liveByHandle[$handle])) {
                    return null;
                }
                return (object)$this->liveByHandle[$handle];
            }
        };
    }

    private function blueprint(array $resources, array $plugins = [], array $frontend = []): array
    {
        return [
            'packageHandle' => 'demo-kit',
            'resources' => $resources,
            'requiredPlugins' => $plugins,
            'frontendRequirements' => $frontend,
        ];
    }

    public function testNewResourceInNewBlueprintBecomesACreateStep()
    {
        $planner = new SynchronizationPlanner([
            'categoryGroupScanner' => $this->fakeScanner([]),
            'tagGroupScanner' => $this->fakeScanner([]),
            'assetVolumeScanner' => $this->fakeScanner([]),
            'sectionScanner' => $this->fakeScanner([]),
        ]);

        $old = $this->blueprint(['categoryGroups' => []]);
        $new = $this->blueprint(['categoryGroups' => [['handle' => 'news', 'name' => 'News', 'maxLevels' => null, 'defaultPlacement' => 'end']]]);

        $plan = $planner->plan('demo-kit', '1.0.0', '1.1.0', $old, $new);

        $this->assertCount(1, $plan->steps);
        $this->assertSame('create', $plan->steps[0]->action);
        $this->assertSame('categoryGroups:news', $plan->steps[0]->key);
    }

    public function testUnchangedResourceProducesNoStep()
    {
        $def = ['handle' => 'news', 'name' => 'News', 'maxLevels' => null, 'defaultPlacement' => 'end'];
        $planner = new SynchronizationPlanner([
            'categoryGroupScanner' => $this->fakeScanner([]),
            'tagGroupScanner' => $this->fakeScanner([]),
            'assetVolumeScanner' => $this->fakeScanner([]),
            'sectionScanner' => $this->fakeScanner([]),
        ]);

        $old = $this->blueprint(['categoryGroups' => [$def]]);
        $new = $this->blueprint(['categoryGroups' => [$def]]);

        $plan = $planner->plan('demo-kit', '1.0.0', '1.1.0', $old, $new);

        $this->assertSame([], $plan->steps);
        $this->assertSame([], $plan->conflicts);
    }

    public function testChangedResourceWithNoLocalDriftBecomesAnUpdateStep()
    {
        $oldDef = ['handle' => 'news', 'name' => 'News', 'maxLevels' => null, 'defaultPlacement' => 'end'];
        $newDef = ['handle' => 'news', 'name' => 'Newsroom', 'maxLevels' => null, 'defaultPlacement' => 'end'];

        // Live state still matches the OLD definition - no local edit since install.
        $planner = new SynchronizationPlanner([
            'categoryGroupScanner' => $this->fakeScanner(['news' => ['name' => 'News', 'maxLevels' => null, 'defaultPlacement' => 'end']]),
            'tagGroupScanner' => $this->fakeScanner([]),
            'assetVolumeScanner' => $this->fakeScanner([]),
            'sectionScanner' => $this->fakeScanner([]),
        ]);

        $old = $this->blueprint(['categoryGroups' => [$oldDef]]);
        $new = $this->blueprint(['categoryGroups' => [$newDef]]);

        $plan = $planner->plan('demo-kit', '1.0.0', '1.1.0', $old, $new);

        $this->assertCount(1, $plan->steps);
        $this->assertSame('update', $plan->steps[0]->action);
        $this->assertSame([], $plan->conflicts);
    }

    public function testChangedResourceWithLocalDriftBecomesAConflictNotAnUpdate()
    {
        $oldDef = ['handle' => 'news', 'name' => 'News', 'maxLevels' => null, 'defaultPlacement' => 'end'];
        $newDef = ['handle' => 'news', 'name' => 'Newsroom', 'maxLevels' => null, 'defaultPlacement' => 'end'];

        // Live state has drifted from the OLD definition (someone renamed it in the CP) - must not be silently overwritten.
        $planner = new SynchronizationPlanner([
            'categoryGroupScanner' => $this->fakeScanner(['news' => ['name' => 'Locally Renamed', 'maxLevels' => null, 'defaultPlacement' => 'end']]),
            'tagGroupScanner' => $this->fakeScanner([]),
            'assetVolumeScanner' => $this->fakeScanner([]),
            'sectionScanner' => $this->fakeScanner([]),
        ]);

        $old = $this->blueprint(['categoryGroups' => [$oldDef]]);
        $new = $this->blueprint(['categoryGroups' => [$newDef]]);

        $plan = $planner->plan('demo-kit', '1.0.0', '1.1.0', $old, $new);

        $this->assertSame([], $plan->steps);
        $this->assertCount(1, $plan->conflicts);
        $this->assertSame(Conflict::TYPE_LOCALLY_MODIFIED, $plan->conflicts[0]->type);
    }

    public function testDroppedUnmodifiedResourceBecomesAnOptInRemoval()
    {
        $oldDef = ['handle' => 'news', 'name' => 'News', 'maxLevels' => null, 'defaultPlacement' => 'end'];

        $planner = new SynchronizationPlanner([
            'categoryGroupScanner' => $this->fakeScanner(['news' => ['name' => 'News', 'maxLevels' => null, 'defaultPlacement' => 'end']]),
            'tagGroupScanner' => $this->fakeScanner([]),
            'assetVolumeScanner' => $this->fakeScanner([]),
            'sectionScanner' => $this->fakeScanner([]),
        ]);

        $old = $this->blueprint(['categoryGroups' => [$oldDef]]);
        $new = $this->blueprint(['categoryGroups' => []]);

        $plan = $planner->plan('demo-kit', '1.0.0', '1.1.0', $old, $new);

        $this->assertSame([], $plan->steps);
        $this->assertSame([], $plan->conflicts);
        $this->assertCount(1, $plan->removals);
        $this->assertSame('remove', $plan->removals[0]->action);
    }

    public function testDroppedButLocallyModifiedResourceBecomesAConflictNotARemoval()
    {
        $oldDef = ['handle' => 'news', 'name' => 'News', 'maxLevels' => null, 'defaultPlacement' => 'end'];

        $planner = new SynchronizationPlanner([
            'categoryGroupScanner' => $this->fakeScanner(['news' => ['name' => 'Locally Renamed', 'maxLevels' => null, 'defaultPlacement' => 'end']]),
            'tagGroupScanner' => $this->fakeScanner([]),
            'assetVolumeScanner' => $this->fakeScanner([]),
            'sectionScanner' => $this->fakeScanner([]),
        ]);

        $old = $this->blueprint(['categoryGroups' => [$oldDef]]);
        $new = $this->blueprint(['categoryGroups' => []]);

        $plan = $planner->plan('demo-kit', '1.0.0', '1.1.0', $old, $new);

        $this->assertSame([], $plan->removals);
        $this->assertCount(1, $plan->conflicts);
        $this->assertSame(Conflict::TYPE_LOCALLY_MODIFIED, $plan->conflicts[0]->type);
    }

    public function testPluginDiffClassifiesAddedUpdatedAndRemoved()
    {
        $planner = new SynchronizationPlanner([
            'categoryGroupScanner' => $this->fakeScanner([]),
            'tagGroupScanner' => $this->fakeScanner([]),
            'assetVolumeScanner' => $this->fakeScanner([]),
            'sectionScanner' => $this->fakeScanner([]),
        ]);

        $old = $this->blueprint([], [
            ['handle' => 'ckeditor', 'package' => 'craftcms/ckeditor', 'versionConstraint' => '^3.0.0'],
            ['handle' => 'seo', 'package' => 'ether/seo', 'versionConstraint' => '^4.0.0'],
        ]);
        $new = $this->blueprint([], [
            ['handle' => 'ckeditor', 'package' => 'craftcms/ckeditor', 'versionConstraint' => '^4.0.0'],
            ['handle' => 'vite', 'package' => 'craftcms/vite', 'versionConstraint' => '^2.0.0'],
        ]);

        $plan = $planner->plan('demo-kit', '1.0.0', '1.1.0', $old, $new);

        $this->assertCount(1, $plan->pluginChanges['added']);
        $this->assertSame('vite', $plan->pluginChanges['added'][0]['handle']);
        $this->assertCount(1, $plan->pluginChanges['updated']);
        $this->assertSame('ckeditor', $plan->pluginChanges['updated'][0]['handle']);
        $this->assertCount(1, $plan->pluginChanges['removed']);
        $this->assertSame('seo', $plan->pluginChanges['removed'][0]['handle']);
        // A version-constraint change on an already-required plugin is surfaced as a dependency conflict.
        $this->assertCount(1, $plan->conflicts);
        $this->assertSame(Conflict::TYPE_DEPENDENCY, $plan->conflicts[0]->type);
    }

    public function testMissingPackageHandleInNewBlueprintProducesAnError()
    {
        $planner = new SynchronizationPlanner([
            'categoryGroupScanner' => $this->fakeScanner([]),
            'tagGroupScanner' => $this->fakeScanner([]),
            'assetVolumeScanner' => $this->fakeScanner([]),
            'sectionScanner' => $this->fakeScanner([]),
        ]);

        $plan = $planner->plan('demo-kit', '1.0.0', '1.1.0', $this->blueprint([]), ['resources' => []]);

        $this->assertNotEmpty($plan->errors);
        $this->assertSame([], $plan->steps);
    }
}
