<?php

namespace site7\studio\services\synchronization;

use craft\base\Component;
use site7\studio\records\InstalledStarterKitRecord;

/**
 * Tracks which Starter Kits are installed, at which version, and what their
 * Blueprint looked like at install/last-sync time (Website Starter Kit
 * System Phase 8) - pure persistence, no comparison/diff logic of its own
 * (see SynchronizationPlanner for that). This is the baseline every sync
 * plan is built against.
 */
class InstalledStarterKitTrackingService extends Component
{
    /** Called once a fresh install (Phase 7) completes successfully - the very first baseline a future sync will diff against. */
    public function recordInstall(string $handle, string $version, array $blueprint): void
    {
        $record = $this->findRecord($handle) ?? new InstalledStarterKitRecord(['handle' => $handle]);

        $record->installedVersion = $version;
        $record->blueprintSnapshot = json_encode($blueprint, JSON_UNESCAPED_SLASHES);
        $record->installedAt = (new \DateTime())->format('Y-m-d H:i:s');
        $record->save(false);
    }

    /** Called once a synchronization run completes successfully - moves the baseline forward to the version just applied. */
    public function recordSync(string $handle, string $version, array $blueprint): void
    {
        $record = $this->findRecord($handle);
        if (!$record) {
            $this->recordInstall($handle, $version, $blueprint);
            return;
        }

        $record->installedVersion = $version;
        $record->blueprintSnapshot = json_encode($blueprint, JSON_UNESCAPED_SLASHES);
        $record->lastSyncedAt = (new \DateTime())->format('Y-m-d H:i:s');
        $record->save(false);
    }

    /** @return array{handle: string, installedVersion: string, blueprint: array, installedAt: string, lastSyncedAt: ?string}|null */
    public function getInstalled(string $handle): ?array
    {
        $record = $this->findRecord($handle);
        if (!$record) {
            return null;
        }

        return [
            'handle' => $record->handle,
            'installedVersion' => $record->installedVersion,
            'blueprint' => json_decode($record->blueprintSnapshot, true) ?: [],
            'installedAt' => $record->installedAt,
            'lastSyncedAt' => $record->lastSyncedAt,
        ];
    }

    /** @return array<int, array{handle: string, installedVersion: string, installedAt: string, lastSyncedAt: ?string}> */
    public function allInstalled(): array
    {
        $records = InstalledStarterKitRecord::find()->all();

        return array_map(fn(InstalledStarterKitRecord $r) => [
            'handle' => $r->handle,
            'installedVersion' => $r->installedVersion,
            'installedAt' => $r->installedAt,
            'lastSyncedAt' => $r->lastSyncedAt,
        ], $records);
    }

    private function findRecord(string $handle): ?InstalledStarterKitRecord
    {
        return InstalledStarterKitRecord::find()->where(['handle' => $handle])->one();
    }
}
