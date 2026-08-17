<?php

namespace site7\studio\services\synchronization;

use Craft;
use craft\base\Component;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\Site7Studio;

/**
 * The three-way baseline/live/incoming comparison Step 6 requires - reuses
 * InstalledFileBaselineService (Step 5, the "baseline" side) and
 * PackageArchiveHelper::computeFileChecksum() (Step 3, the same hashing
 * convention every checksum in this project already uses) rather than either
 * a second baseline store or a second hashing routine.
 *
 * Deliberately read-only and separate from execution (the same "diff/plan
 * strictly separate from execution" split SynchronizationPlanner already
 * established for Starter-Kit-level native Craft resources - see that
 * class's own docblock). This is the same *pattern* applied at file
 * granularity, not a subclass or shared base class: the inputs (file
 * checksums vs. Craft-resource field arrays) are too different to force into
 * one hierarchy without an awkward abstraction, and SynchronizationPlanner
 * itself stays untouched and out of scope for package-file updates.
 */
class PackageUpdatePlanner extends Component
{
    /** Baseline, live, and incoming all agree - nothing to do. */
    public const RESULT_UNCHANGED = 'unchanged';
    /** Live still matches baseline; incoming differs - safe to apply automatically. */
    public const RESULT_SAFE_UPDATE = 'safe_update';
    /** Live has drifted from baseline; incoming matches baseline - a local-only edit, never touched. */
    public const RESULT_LOCAL_MODIFICATION = 'local_modification';
    /** Live has drifted from baseline AND incoming differs from baseline - never auto-applied. */
    public const RESULT_CONFLICT = 'conflict';
    /** The live file no longer exists at all. */
    public const RESULT_LOCAL_DELETION = 'local_deletion';
    /** The incoming version no longer contains this file, and live still matches baseline - safe to remove. */
    public const RESULT_SAFE_REMOVAL = 'safe_removal';
    /** The incoming version no longer contains this file, but live has drifted from baseline - never auto-removed. */
    public const RESULT_REMOVAL_CONFLICT = 'removal_conflict';

    /**
     * Plans every tracked installed file for $packageId against $incomingFiles.
     * Only files that already have a Step 5 baseline row are considered -
     * a targetPath with no baseline at all is a fresh install, not an
     * update, and out of this planner's scope.
     *
     * @param array<string, string|null> $incomingFiles targetPath => the
     *   incoming version's checksum for that file, or null if the incoming
     *   version does not contain it at all.
     * @return array<int, array{targetPath: string, resourceHandle: string,
     *   result: string, baselineChecksum: string, liveChecksum: ?string,
     *   incomingChecksum: ?string, message: string}>
     */
    public function plan(int $packageId, array $incomingFiles): array
    {
        $baselines = Site7Studio::getInstance()->installedFileBaseline->allForPackage($packageId);

        $results = [];
        foreach ($baselines as $baseline) {
            $liveChecksum = PackageArchiveHelper::computeFileChecksum($this->resolveAbsolutePath($baseline['targetPath']));
            $incomingChecksum = array_key_exists($baseline['targetPath'], $incomingFiles)
                ? $incomingFiles[$baseline['targetPath']]
                : null;

            $results[] = $this->classify($baseline, $liveChecksum, $incomingChecksum);
        }

        return $results;
    }

    /**
     * The six-case decision table itself - pure string comparison, no Craft
     * dependency, deliberately public (and stateless) so it's unit-testable
     * on its own without a live Craft app, unlike plan()/
     * resolveIncomingChecksums() which need real file/archive access.
     *
     * @param array{targetPath: string, resourceHandle: string, installedVersion: string, checksum: string, installedAt: string} $baseline
     */
    public function classify(array $baseline, ?string $liveChecksum, ?string $incomingChecksum): array
    {
        $a = $baseline['checksum'];
        $b = $liveChecksum;
        $c = $incomingChecksum;
        $path = $baseline['targetPath'];

        $base = [
            'targetPath' => $path,
            'resourceHandle' => $baseline['resourceHandle'],
            'baselineChecksum' => $a,
            'liveChecksum' => $b,
            'incomingChecksum' => $c,
        ];

        // Case 5 - locally deleted since install. Never auto-recreated here -
        // that would silently overwrite whatever the developer intended by
        // removing it, exactly the failure mode this whole system exists to
        // prevent.
        if ($b === null) {
            return $base + [
                'result' => self::RESULT_LOCAL_DELETION,
                'message' => "'{$path}' was installed by this package but no longer exists on disk - not recreated automatically.",
            ];
        }

        // Case 6 - the incoming version no longer contains this file.
        if ($c === null) {
            if ($b === $a) {
                return $base + [
                    'result' => self::RESULT_SAFE_REMOVAL,
                    'message' => "'{$path}' is no longer part of the incoming version and is unmodified - safe to remove.",
                ];
            }
            return $base + [
                'result' => self::RESULT_REMOVAL_CONFLICT,
                'message' => "'{$path}' is no longer part of the incoming version, but was locally modified - left in place.",
            ];
        }

        // Case 1
        if ($a === $b && $a === $c) {
            return $base + [
                'result' => self::RESULT_UNCHANGED,
                'message' => "'{$path}' is unchanged - no update needed.",
            ];
        }

        // Case 2
        if ($a === $b && $a !== $c) {
            return $base + [
                'result' => self::RESULT_SAFE_UPDATE,
                'message' => "'{$path}' was not locally modified - safe to update to the incoming version.",
            ];
        }

        // Case 3
        if ($a !== $b && $a === $c) {
            return $base + [
                'result' => self::RESULT_LOCAL_MODIFICATION,
                'message' => "'{$path}' was locally modified since install - left untouched.",
            ];
        }

        // Case 4 - $a !== $b && $a !== $c, regardless of whether $b === $c.
        return $base + [
            'result' => self::RESULT_CONFLICT,
            'message' => "'{$path}' was both locally modified and changed in the incoming version - requires manual resolution.",
        ];
    }

    /**
     * Resolves the incoming checksum for each of $baselineTargetPaths by
     * extracting the matching file from $archivePath (a real .s7pkg
     * produced by PackageExportService/VersionManagerService - see
     * PackageArchiveHelper, reused here, never a second archive reader).
     * Currently only understands the one real installed-file mapping this
     * codebase has - templates/_blocks/{handle}.twig corresponds to
     * packages/{packageHandle}/template.twig inside the archive, matching
     * CraftResourceService's own "MVP: assume first block handle" scope
     * (a Section package installs exactly one template file today). A
     * targetPath outside that shape resolves to null (treated as "the
     * incoming version doesn't have it").
     *
     * @param string[] $baselineTargetPaths
     * @return array<string, string|null> targetPath => checksum|null
     */
    public function resolveIncomingChecksums(string $packageHandle, string $archivePath, array $baselineTargetPaths): array
    {
        $incoming = [];
        $tempDir = Craft::getAlias('@storage') . '/runtime/site7-studio/update-check/' . uniqid('', true);

        try {
            foreach ($baselineTargetPaths as $targetPath) {
                if (!preg_match('#^templates/_blocks/[^/]+\.twig$#', $targetPath)) {
                    $incoming[$targetPath] = null;
                    continue;
                }

                $entryName = 'packages/' . $packageHandle . '/template.twig';
                PackageArchiveHelper::extractZip($archivePath, $tempDir, [$entryName]);
                $extractedPath = $tempDir . '/' . $entryName;
                $incoming[$targetPath] = PackageArchiveHelper::computeFileChecksum($extractedPath);
            }
        } finally {
            if (is_dir($tempDir)) {
                \craft\helpers\FileHelper::removeDirectory($tempDir);
            }
        }

        return $incoming;
    }

    /**
     * $targetPath is stored relative to the Craft install root (e.g.
     * templates/_blocks/ctaBanner.twig - see the site7_installed_files
     * migration's own docblock); resolved via getSiteTemplatesPath()'s
     * parent rather than a hardcoded root, the same way every other
     * "resolve a Craft-root-relative path" call in this codebase does.
     */
    private function resolveAbsolutePath(string $targetPath): string
    {
        $root = dirname(rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/'));
        return $root . '/' . ltrim($targetPath, '/');
    }
}
