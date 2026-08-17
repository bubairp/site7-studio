<?php

namespace site7\studio\services\publishing;

use craft\base\Component;
use site7\studio\events\publishing\VersionCreatedEvent;
use site7\studio\interfaces\VersionManagerInterface;
use site7\studio\records\PackageRecord;
use site7\studio\records\PackageVersionRecord;
use site7\studio\services\PackageAuthoringService;
use site7\studio\services\PackageExportService;
use site7\studio\Site7Studio;

/**
 * Semantic-version bumping and history - see VersionManagerInterface's
 * docblock for why this reuses (rather than duplicates) the existing
 * site7_package_versions table.
 */
class VersionManagerService extends Component implements VersionManagerInterface
{
    private const BUMP_TYPES = ['patch', 'minor', 'major'];

    /**
     * @inheritdoc
     */
    public function createVersion(string $handle, string $bumpType, ?string $releaseNotes = null): PackageVersionRecord
    {
        if (!in_array($bumpType, self::BUMP_TYPES, true)) {
            throw new \Exception("Unknown bump type '{$bumpType}' - expected patch, minor, or major.");
        }

        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception("Package '{$handle}' was not found.");
        }

        // Bumps off the HIGHEST version ever recorded for this package, not
        // just the manifest's current value - after a rollback (Step 7)
        // restores an older manifest.json, the manifest alone would bump
        // straight back into a version number that already exists in
        // history (e.g. rolled back to 1.0.0, next patch bump would try
        // 1.0.1, which v2 already used), which recordVersion()'s dedup
        // guard would then silently treat as "nothing to do" rather than
        // actually recording a new version. Exactly the gap the original
        // Step 7 plan flagged for this method.
        $newVersion = $this->bumpVersion($this->resolveBumpBaseVersion($record), $bumpType);

        // Writes through the exact same manifest.json + PackageRecord path
        // every other metadata edit already uses - no separate write path
        // for "version" specifically.
        (new PackageAuthoringService())->updatePackage($handle, ['version' => $newVersion]);

        // Reuses PackageExportService::exportPackage() for the actual .s7pkg
        // archive + content checksum - it already calls
        // MarketplaceService::recordVersion() internally (dedup-safe on
        // packageId+version) once the archive is built, so a version created
        // here ends up with exactly the same real archivePath/checksum a
        // manual export would produce, through the one existing recording
        // path - not a second one. Dependencies are deliberately excluded
        // (includeDependencies: false): a version row represents this
        // package's own state, not a bundle of everything it requires -
        // those are versioned independently, by their own packages.
        (new PackageExportService())->exportPackage($handle, false);

        $versionRecord = PackageVersionRecord::find()
            ->where(['packageId' => $record->id, 'version' => $newVersion])
            ->one();
        if (!$versionRecord) {
            // exportPackage() always records a version row for its own root
            // handle - if it didn't, something upstream broke silently
            // (e.g. the manifest write above didn't actually take, as it
            // used to for a locked imported package). Fail loudly rather
            // than return a version that doesn't have a real archive behind it.
            throw new \Exception("Version '{$newVersion}' was exported but its history row could not be found.");
        }

        if ($releaseNotes !== null) {
            $versionRecord->releaseNotes = $releaseNotes;
            $versionRecord->save();
        }

        Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new VersionCreatedEvent([
            'handle' => $handle,
            'version' => $versionRecord,
        ]));

        return $versionRecord;
    }

    /**
     * @inheritdoc
     */
    public function getVersionHistory(string $handle): array
    {
        $record = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        if (!$record) {
            return [];
        }

        return PackageVersionRecord::find()
            ->where(['packageId' => $record->id])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();
    }

    /**
     * The manifest's current version, or the highest version ever recorded
     * in site7_package_versions for this package - whichever is greater.
     * Equal to the manifest's version in every normal (forward-only) case;
     * only diverges right after a rollback, where the manifest has just
     * been restored to an older snapshot but this package's version
     * history still remembers every version that came after it.
     */
    private function resolveBumpBaseVersion(PackageRecord $record): string
    {
        $highest = $record->version;
        foreach (PackageVersionRecord::find()->where(['packageId' => $record->id])->all() as $existing) {
            if (preg_match('/^\d+\.\d+\.\d+(?:[-+].*)?$/', $existing->version) && version_compare($existing->version, $highest, '>')) {
                $highest = $existing->version;
            }
        }
        return $highest;
    }

    private function bumpVersion(string $version, string $bumpType): string
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)(?:[-+].*)?$/', $version, $matches)) {
            throw new \Exception("Version '{$version}' is not a valid semantic version (expected major.minor.patch).");
        }

        [, $major, $minor, $patch] = array_map('intval', $matches);

        return match ($bumpType) {
            'major' => ($major + 1) . '.0.0',
            'minor' => $major . '.' . ($minor + 1) . '.0',
            'patch' => $major . '.' . $minor . '.' . ($patch + 1),
        };
    }
}
