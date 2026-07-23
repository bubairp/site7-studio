<?php

namespace site7\studio\services\publishing;

use craft\base\Component;
use site7\studio\events\publishing\VersionCreatedEvent;
use site7\studio\interfaces\VersionManagerInterface;
use site7\studio\records\PackageVersionRecord;
use site7\studio\services\PackageAuthoringService;
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

        $versionRecord = new PackageVersionRecord();
        $versionRecord->packageId = $record->id;
        $versionRecord->version = $newVersion;
        $versionRecord->releaseDate = (new \DateTime())->format('Y-m-d H:i:s');
        $versionRecord->releaseNotes = $releaseNotes;
        $versionRecord->save();

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
        $parts = array_map('intval', explode('.', $version) + [0, 0, 0]);
        [$major, $minor, $patch] = [$parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0];

        return match ($bumpType) {
            'major' => ($major + 1) . '.0.0',
            'minor' => $major . '.' . ($minor + 1) . '.0',
            'patch' => $major . '.' . $minor . '.' . ($patch + 1),
        };
    }
}
