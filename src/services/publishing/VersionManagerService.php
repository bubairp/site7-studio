<?php

namespace site7\studio\services\publishing;

use craft\base\Component;
use site7\studio\events\publishing\VersionCreatedEvent;
use site7\studio\interfaces\VersionManagerInterface;
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

        $newVersion = $this->bumpVersion($record->version, $bumpType);

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
