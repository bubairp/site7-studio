<?php

namespace site7\studio\jobs;

use craft\queue\BaseJob;
use site7\studio\Site7Studio;
use site7\studio\models\installation\InstallationSession;

/**
 * Runs a confirmed SynchronizationSession in the background (Website
 * Starter Kit System Phase 8), mirroring InstallStarterKitJob exactly.
 * Contains no synchronization logic itself - only calls
 * SynchronizationOrchestratorService::execute(), which in turn drives the
 * create/update portion through the existing, unchanged
 * InstallationOrchestratorService (so the composer/plugin-install/
 * project-config process boundaries Phase 7 already established still
 * apply here without this class knowing anything about them).
 */
class SyncStarterKitJob extends BaseJob
{
    public string $sessionUid;

    public function execute($queue): void
    {
        $plugin = Site7Studio::getInstance();
        $session = $plugin->synchronizationSessions->loadSession($this->sessionUid);
        if ($session === null) {
            throw new \RuntimeException("No SynchronizationSession found for uid {$this->sessionUid}.");
        }

        $plugin->synchronizationOrchestrator->execute($session, function (InstallationSession $s) use ($queue) {
            $total = max(count(InstallationSession::STAGE_ORDER), 1);
            $done = count($s->stagesCompleted);
            $this->setProgress($queue, min($done / $total, 1.0), $s->currentStep);
        });

        $plugin->synchronizationSessions->save($session);
    }

    protected function defaultDescription(): ?string
    {
        return 'Synchronizing Starter Kit (session ' . $this->sessionUid . ')';
    }
}
