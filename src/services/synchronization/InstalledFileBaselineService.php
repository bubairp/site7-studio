<?php

namespace site7\studio\services\synchronization;

use craft\base\Component;
use site7\studio\records\PackageInstalledFileRecord;

/**
 * Tracks the baseline checksum of every file a package has actually written
 * onto this site's disk (Step 5 - Installed-File Baseline) - pure
 * persistence, no comparison/diff logic of its own, the same separation
 * InstalledStarterKitTrackingService already establishes for whole-Blueprint
 * baselines (see that class's own docblock). This is the "baseline" side of
 * the baseline/live/incoming three-way comparison a later update/conflict
 * check (Step 6) will read, not that comparison itself.
 */
class InstalledFileBaselineService extends Component
{
    /**
     * Records (or, for an already-tracked file, updates) this file's
     * baseline - called once a package operation has actually finished
     * writing $targetPath, with $checksum computed from the file exactly as
     * it now sits on disk. Upserts on (packageId, targetPath), so installing/
     * reinstalling the same file never accumulates more than one row for it.
     */
    public function record(int $packageId, string $resourceHandle, string $targetPath, string $installedVersion, string $checksum): void
    {
        $record = $this->findRecord($packageId, $targetPath) ?? new PackageInstalledFileRecord([
            'packageId' => $packageId,
            'targetPath' => $targetPath,
        ]);

        $record->resourceHandle = $resourceHandle;
        $record->installedVersion = $installedVersion;
        $record->checksum = $checksum;
        $record->installedAt = (new \DateTime())->format('Y-m-d H:i:s');
        $record->save(false);
    }

    /**
     * @return array{targetPath: string, resourceHandle: string, installedVersion: string, checksum: string, installedAt: string}|null
     */
    public function getBaseline(int $packageId, string $targetPath): ?array
    {
        $record = $this->findRecord($packageId, $targetPath);
        if (!$record) {
            return null;
        }

        return $this->toBaselineArray($record);
    }

    /**
     * Removes a baseline row entirely - used only when a file has actually
     * been removed from the site by this package (Step 6's "safe removal"
     * case, the incoming version no longer contains it and it was never
     * locally modified). Never called merely because an update attempt
     * happened - only after the file itself was actually deleted.
     */
    public function remove(int $packageId, string $targetPath): void
    {
        PackageInstalledFileRecord::deleteAll(['packageId' => $packageId, 'targetPath' => $targetPath]);
    }

    /**
     * @return array<int, array{targetPath: string, resourceHandle: string, installedVersion: string, checksum: string, installedAt: string}>
     */
    public function allForPackage(int $packageId): array
    {
        $records = PackageInstalledFileRecord::find()->where(['packageId' => $packageId])->all();

        return array_map(fn(PackageInstalledFileRecord $r) => $this->toBaselineArray($r), $records);
    }

    private function findRecord(int $packageId, string $targetPath): ?PackageInstalledFileRecord
    {
        return PackageInstalledFileRecord::find()
            ->where(['packageId' => $packageId, 'targetPath' => $targetPath])
            ->one();
    }

    private function toBaselineArray(PackageInstalledFileRecord $record): array
    {
        return [
            'targetPath' => $record->targetPath,
            'resourceHandle' => $record->resourceHandle,
            'installedVersion' => $record->installedVersion,
            'checksum' => $record->checksum,
            'installedAt' => $record->installedAt,
        ];
    }
}
