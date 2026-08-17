<?php

namespace site7\studio\tests\unit\services\synchronization;

use Codeception\Test\Unit;
use site7\studio\services\synchronization\PackageUpdatePlanner;

/**
 * Covers PackageUpdatePlanner::classify() - the six-case baseline/live/
 * incoming decision table Step 6 requires. Pure string comparison, no live
 * Craft app needed (unlike plan()/resolveIncomingChecksums(), which touch
 * real files/archives and are covered by the Step 6 live DDEV verification
 * instead, same split as every other Craft-dependent method in this plugin).
 */
class PackageUpdatePlannerTest extends Unit
{
    protected \UnitTester $tester;

    private function baseline(string $checksum): array
    {
        return [
            'targetPath' => 'templates/_blocks/ctaBanner.twig',
            'resourceHandle' => 'ctaBanner',
            'installedVersion' => '1.0.0',
            'checksum' => $checksum,
            'installedAt' => '2026-08-17 00:00:00',
        ];
    }

    public function testCase1UnchangedWhenAllThreeAgree()
    {
        $planner = new PackageUpdatePlanner();
        $result = $planner->classify($this->baseline('A'), 'A', 'A');
        $this->assertSame(PackageUpdatePlanner::RESULT_UNCHANGED, $result['result']);
    }

    public function testCase2SafeUpdateWhenOnlyIncomingDiffers()
    {
        $planner = new PackageUpdatePlanner();
        $result = $planner->classify($this->baseline('A'), 'A', 'C');
        $this->assertSame(PackageUpdatePlanner::RESULT_SAFE_UPDATE, $result['result']);
    }

    public function testCase3LocalModificationWhenOnlyLiveDiffers()
    {
        $planner = new PackageUpdatePlanner();
        $result = $planner->classify($this->baseline('A'), 'B', 'A');
        $this->assertSame(PackageUpdatePlanner::RESULT_LOCAL_MODIFICATION, $result['result']);
    }

    public function testCase4ConflictWhenBothLiveAndIncomingDiffer()
    {
        $planner = new PackageUpdatePlanner();
        $result = $planner->classify($this->baseline('A'), 'B', 'C');
        $this->assertSame(PackageUpdatePlanner::RESULT_CONFLICT, $result['result']);
    }

    public function testCase4ConflictEvenWhenLiveAndIncomingCoincidentallyMatchEachOther()
    {
        // A != B, A != C, but B === C - still a conflict per the spec: both
        // diverged from baseline independently, so it's never silently
        // treated as "in agreement" just because they happen to match.
        $planner = new PackageUpdatePlanner();
        $result = $planner->classify($this->baseline('A'), 'B', 'B');
        $this->assertSame(PackageUpdatePlanner::RESULT_CONFLICT, $result['result']);
    }

    public function testCase5LocalDeletionWhenLiveFileMissing()
    {
        $planner = new PackageUpdatePlanner();
        $result = $planner->classify($this->baseline('A'), null, 'C');
        $this->assertSame(PackageUpdatePlanner::RESULT_LOCAL_DELETION, $result['result']);
    }

    public function testCase5LocalDeletionEvenWhenIncomingAlsoLacksTheFile()
    {
        $planner = new PackageUpdatePlanner();
        $result = $planner->classify($this->baseline('A'), null, null);
        $this->assertSame(PackageUpdatePlanner::RESULT_LOCAL_DELETION, $result['result']);
    }

    public function testCase6SafeRemovalWhenIncomingDropsAnUnmodifiedFile()
    {
        $planner = new PackageUpdatePlanner();
        $result = $planner->classify($this->baseline('A'), 'A', null);
        $this->assertSame(PackageUpdatePlanner::RESULT_SAFE_REMOVAL, $result['result']);
    }

    public function testCase6RemovalConflictWhenIncomingDropsALocallyModifiedFile()
    {
        $planner = new PackageUpdatePlanner();
        $result = $planner->classify($this->baseline('A'), 'B', null);
        $this->assertSame(PackageUpdatePlanner::RESULT_REMOVAL_CONFLICT, $result['result']);
    }

    public function testEveryResultCarriesAllThreeChecksumsForAuditability()
    {
        $planner = new PackageUpdatePlanner();
        $result = $planner->classify($this->baseline('A'), 'B', 'C');
        $this->assertSame('A', $result['baselineChecksum']);
        $this->assertSame('B', $result['liveChecksum']);
        $this->assertSame('C', $result['incomingChecksum']);
        $this->assertSame('templates/_blocks/ctaBanner.twig', $result['targetPath']);
        $this->assertSame('ctaBanner', $result['resourceHandle']);
        $this->assertNotEmpty($result['message']);
    }
}
