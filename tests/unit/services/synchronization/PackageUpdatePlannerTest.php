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

    // --- Step 8.2: resolveArchiveEntryName() - the shared targetPath ->
    // archive-entry resolver, generalized to cover Step 8.1's ownedFiles.
    // No live Craft app needed - pure regex + zip read.

    private string $tempZipPath;

    protected function _after()
    {
        if (isset($this->tempZipPath) && file_exists($this->tempZipPath)) {
            unlink($this->tempZipPath);
        }
    }

    private function buildFixtureArchive(array $manifestData): string
    {
        $this->tempZipPath = sys_get_temp_dir() . '/site7_planner_test_' . uniqid('', true) . '.s7pkg';
        $zip = new \ZipArchive();
        $zip->open($this->tempZipPath, \ZipArchive::CREATE);
        $zip->addFromString('packages/cta-banner/manifest.json', json_encode($manifestData));
        $zip->addFromString('packages/cta-banner/template.twig', '<div>twig content</div>');
        $zip->addFromString('packages/cta-banner/frontend/src/css/components/ctaBanner.css', '.cta { color: red; }');
        $zip->close();
        return $this->tempZipPath;
    }

    public function testResolveArchiveEntryNameStillResolvesTheBuiltInTemplateMappingUnchanged()
    {
        $archivePath = $this->buildFixtureArchive(['ownedFiles' => []]);
        $planner = new PackageUpdatePlanner();

        $entry = $planner->resolveArchiveEntryName('cta-banner', $archivePath, 'templates/_blocks/ctaBanner.twig');

        $this->assertSame('packages/cta-banner/template.twig', $entry);
    }

    public function testResolveArchiveEntryNameResolvesAnOwnedFileFromTheArchivesOwnManifest()
    {
        $archivePath = $this->buildFixtureArchive([
            'ownedFiles' => [
                ['sourcePath' => 'frontend/src/css/components/ctaBanner.css', 'targetPath' => 'frontend/src/css/components/ctaBanner.css', 'type' => 'frontend-css'],
            ],
        ]);
        $planner = new PackageUpdatePlanner();

        $entry = $planner->resolveArchiveEntryName('cta-banner', $archivePath, 'frontend/src/css/components/ctaBanner.css');

        $this->assertSame('packages/cta-banner/frontend/src/css/components/ctaBanner.css', $entry);
    }

    public function testResolveArchiveEntryNameReturnsNullForAPathNeitherTemplateNorOwned()
    {
        $archivePath = $this->buildFixtureArchive(['ownedFiles' => []]);
        $planner = new PackageUpdatePlanner();

        $entry = $planner->resolveArchiveEntryName('cta-banner', $archivePath, 'frontend/src/css/components/unrelated.css');

        $this->assertNull($entry);
    }

    public function testResolveArchiveEntryNameUsesThisArchivesOwnOwnedFilesNotSomeOtherVersions()
    {
        // A version that never declared this owned file (e.g. rolling back
        // to an older version predating it) must not resolve it, even if a
        // *different*, later version's on-disk manifest currently does.
        $archivePath = $this->buildFixtureArchive(['ownedFiles' => []]);
        $planner = new PackageUpdatePlanner();

        $entry = $planner->resolveArchiveEntryName('cta-banner', $archivePath, 'frontend/src/css/components/ctaBanner.css');

        $this->assertNull($entry);
    }
}
