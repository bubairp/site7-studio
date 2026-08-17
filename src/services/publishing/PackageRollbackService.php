<?php

namespace site7\studio\services\publishing;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use site7\studio\records\PackageVersionRecord;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\services\synchronization\PackageUpdatePlanner;
use site7\studio\Site7Studio;

/**
 * Rollback (Step 7) - restores a package to a previously recorded immutable
 * version, using that version's own real .s7pkg archive (Step 4) - never a
 * second archive/version mechanism, and never a blind extraction over
 * whatever's currently on disk.
 *
 * Two genuinely different things get "restored," with two different safety
 * rules, and this service is deliberately explicit about which is which:
 *
 * 1. The package's OWN source directory (packages/{handle}/manifest.json,
 *    fields.yaml, matrix.yaml, template.twig, preview/) - this is SITE7
 *    Studio's own managed storage, never hand-edited by a developer outside
 *    Import/Sync/the Package Editor, so replacing it wholesale from the
 *    archive is exactly as safe as PackageImportService already treats a
 *    fresh import's directory copy - reused via PackageArchiveHelper::
 *    replaceDirectory(), not duplicated.
 *
 * 2. The INSTALLED site file(s) (templates/_blocks/{handle}.twig) - this
 *    genuinely can be hand-edited by a developer working on the live site,
 *    so it goes through the exact same baseline/live/target three-way
 *    comparison Step 6 already built (PackageUpdatePlanner via
 *    PackageManagerService::updateInstalledFiles()) - a locally-modified or
 *    conflicting installed file is never overwritten just because the
 *    package's own source moved.
 *
 * No new PackageVersionRecord is created and no existing one is ever
 * modified - rollback changes which version is CURRENTLY installed, not
 * version history. v1/v2/v3's rows, archivePaths, and archive files are
 * never touched, regardless of how many times a rollback runs.
 */
class PackageRollbackService extends Component
{
    /**
     * @return array{packageHandle: string, fromVersion: string, toVersion: string,
     *   fileResults: array, allSafe: bool}
     * @throws \Exception if the package or a real archive for $toVersionRecordId can't be found.
     */
    public function rollback(string $handle, int $toVersionRecordId): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception("Package '{$handle}' was not found.");
        }

        $versionRecord = PackageVersionRecord::findOne(['id' => $toVersionRecordId, 'packageId' => $record->id]);
        if (!$versionRecord || !$versionRecord->archivePath || !file_exists($versionRecord->archivePath)) {
            throw new \Exception("Version #{$toVersionRecordId} has no real archive to roll back to.");
        }

        $fromVersion = $record->version;

        $this->restorePackageSource($handle, $versionRecord->archivePath);

        // Re-sync the DB record from the just-restored manifest.json - its
        // version/name/etc. now read back exactly as they did at
        // $toVersionRecordId, the same way any other manifest write already
        // propagates via discoverPackages().
        $packageManager->discoverPackages();

        // The exact same safety check Step 6 uses for a forward update -
        // PackageUpdatePlanner doesn't know or care whether the target
        // version is newer or older than what's currently installed, so
        // this is genuine reuse, not a lookalike second implementation.
        $fileResults = $packageManager->updateInstalledFiles($handle, $toVersionRecordId);

        $fileResults = $this->reconcileAlreadyMatchingConflicts($record->id, $versionRecord->version, $fileResults);

        $allSafe = !(bool)array_filter(
            $fileResults,
            fn(array $item) => in_array($item['result'], [
                PackageUpdatePlanner::RESULT_CONFLICT,
                PackageUpdatePlanner::RESULT_LOCAL_MODIFICATION,
                PackageUpdatePlanner::RESULT_LOCAL_DELETION,
                PackageUpdatePlanner::RESULT_REMOVAL_CONFLICT,
            ], true) && empty($item['alreadyMatchesTarget']),
        );

        return [
            'packageHandle' => $handle,
            'fromVersion' => $fromVersion,
            'toVersion' => $versionRecord->version,
            'fileResults' => $fileResults,
            'allSafe' => $allSafe,
        ];
    }

    /**
     * Replaces packages/{handle}/ with exactly what $archivePath's own
     * packages/{handle}/ subtree contains - the package's own managed
     * source, restored verbatim. Deliberately does NOT call
     * PackageBackupService first: that service keeps only the latest backup
     * per handle and would delete an OLDER PackageVersionRecord's own
     * archivePath whenever that archive already lives in the same
     * marketplace-repo folder - confirmed live during Step 6 to silently
     * destroy version history exactly this way. Every version already has
     * its own permanent, independent archive (Step 4) - that already is
     * this operation's safety net.
     */
    private function restorePackageSource(string $handle, string $archivePath): void
    {
        $tempDir = Craft::getAlias('@storage') . '/runtime/site7-studio/rollback/' . uniqid('', true);
        try {
            PackageArchiveHelper::extractZip($archivePath, $tempDir);

            $extractedPackageDir = $tempDir . '/packages/' . $handle;
            if (!is_dir($extractedPackageDir)) {
                throw new \Exception("This archive does not contain packages/{$handle}/ - cannot roll back.");
            }

            $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($handle);
            if (!$packagePath) {
                throw new \Exception('Package not found on disk.');
            }

            PackageArchiveHelper::replaceDirectory($extractedPackageDir, $packagePath);
        } finally {
            if (is_dir($tempDir)) {
                FileHelper::removeDirectory($tempDir);
            }
        }
    }

    /**
     * Rollback-specific refinement, layered on top of Step 6's plan/apply
     * result rather than changing PackageUpdatePlanner::classify() itself
     * (which stays exactly as Step 6 defined and tested it - baseline !=
     * live and baseline != incoming is always reported as a conflict,
     * correctly, since both diverged from the baseline independently).
     *
     * For rollback specifically: if the live file already contains exactly
     * the rollback target's bytes (liveChecksum === incomingChecksum), there
     * is nothing destructive left to do - the file already reads as the
     * desired result, even though it also differs from the baseline. This
     * never triggers a write (there is nothing to write - it already
     * matches), but the baseline is advanced to reflect that reality, so a
     * later comparison doesn't keep reporting a conflict for a file that
     * genuinely already matches what's being rolled back to.
     */
    private function reconcileAlreadyMatchingConflicts(int $packageId, string $toVersion, array $fileResults): array
    {
        $baselineService = Site7Studio::getInstance()->installedFileBaseline;

        foreach ($fileResults as &$item) {
            $isConflictKind = in_array($item['result'], [
                PackageUpdatePlanner::RESULT_CONFLICT,
                PackageUpdatePlanner::RESULT_REMOVAL_CONFLICT,
            ], true);

            if ($isConflictKind && $item['liveChecksum'] !== null && $item['liveChecksum'] === $item['incomingChecksum']) {
                $item['alreadyMatchesTarget'] = true;
                $item['message'] = "'{$item['targetPath']}' already matches the rollback target - no action needed.";

                $baselineService->record(
                    $packageId,
                    $item['resourceHandle'],
                    $item['targetPath'],
                    $toVersion,
                    $item['liveChecksum'],
                );
            } else {
                $item['alreadyMatchesTarget'] = false;
            }
        }
        unset($item);

        return $fileResults;
    }
}
