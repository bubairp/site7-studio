<?php

namespace site7\studio\services\installation\executors;

use site7\studio\interfaces\StepExecutorInterface;
use site7\studio\services\StarterKitInstallationService;

/**
 * Installs the Starter Kit's captured pages and Global Set values - a thin
 * wrapper around StarterKitInstallationService (Phase 1, already existing
 * and verified), not a reimplementation. That service already replays
 * pages through the Create-from-Template mechanism and restores Global Set
 * field values, reporting per-page skips rather than failing the whole
 * install.
 */
class ContentInstallExecutor implements StepExecutorInterface
{
    public function execute(array $steps, bool $dryRun): array
    {
        $results = [];

        foreach ($steps as $step) {
            $handle = $step->payload['packageHandle'];

            if ($dryRun) {
                $results[] = ['step' => $step, 'status' => 'skipped', 'message' => "Dry run - would install captured pages/Global Sets for '{$handle}'."];
                continue;
            }

            try {
                $summary = (new StarterKitInstallationService())->installStarterKit($handle);
                $message = sprintf(
                    '%d page(s) created, %d template package(s) installed, %d Global Set(s) restored%s.',
                    count($summary['createdEntries']),
                    count($summary['installedTemplates']),
                    count($summary['installedGlobals']),
                    empty($summary['skipped']) ? '' : ', ' . count($summary['skipped']) . ' item(s) skipped'
                );
                $status = empty($summary['createdEntries']) && !empty($summary['skipped']) ? 'failed' : 'completed';
                $results[] = ['step' => $step, 'status' => $status, 'message' => $message];
            } catch (\Throwable $e) {
                $results[] = ['step' => $step, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }
}
