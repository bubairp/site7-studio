<?php

namespace site7\studio\services\synchronization;

use craft\base\Component;
use site7\studio\records\SynchronizationHistoryRecord;

/**
 * Records and retrieves synchronization run history (Website Starter Kit
 * System Phase 8) - one row per run, successful or not. Pure persistence;
 * SynchronizationOrchestratorService decides what to record and when.
 */
class SynchronizationHistoryService extends Component
{
    public function recordResult(string $handle, string $fromVersion, string $toVersion, string $status, array $report): void
    {
        $record = new SynchronizationHistoryRecord([
            'handle' => $handle,
            'fromVersion' => $fromVersion,
            'toVersion' => $toVersion,
            'status' => $status,
            'report' => json_encode($report, JSON_UNESCAPED_SLASHES),
        ]);
        $record->save(false);
    }

    /** @return array<int, array{handle: string, fromVersion: string, toVersion: string, status: string, report: array, dateCreated: string}> most recent first */
    public function historyFor(string $handle): array
    {
        $records = SynchronizationHistoryRecord::find()
            ->where(['handle' => $handle])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();

        return array_map(fn(SynchronizationHistoryRecord $r) => [
            'handle' => $r->handle,
            'fromVersion' => $r->fromVersion,
            'toVersion' => $r->toVersion,
            'status' => $r->status,
            'report' => json_decode($r->report, true) ?: [],
            'dateCreated' => (string)$r->dateCreated,
        ], $records);
    }
}
