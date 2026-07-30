<?php

namespace site7\studio\jobs;

use craft\queue\BaseJob;
use site7\studio\Site7Studio;
use site7\studio\models\installation\InstallationSession;

/**
 * Runs an InstallationSession in the background (Website Starter Kit System
 * Phase 7) so the CP wizard's Execute step stays responsive instead of
 * blocking a web request for however long Composer/npm/content installation
 * takes. Contains no installation logic itself - it only calls
 * InstallationOrchestratorService::runToCompletion(), the exact same entry
 * point the console command uses, and reports progress through Craft's own
 * queue progress bar as a convenience; the wizard's own polling of the
 * InstallationSession record (updated live by each stage's subprocess) is
 * the actual source of step-by-step detail.
 */
class InstallStarterKitJob extends BaseJob
{
    public string $sessionUid;

    public function execute($queue): void
    {
        $orchestrator = Site7Studio::getInstance()->installationOrchestrator;

        $final = $orchestrator->runToCompletion($this->sessionUid, function (InstallationSession $session) use ($queue) {
            $total = max(count(InstallationSession::STAGE_ORDER), 1);
            $done = count($session->stagesCompleted);
            $this->setProgress($queue, min($done / $total, 1.0), $session->currentStep);
        });

        // Website Starter Kit System Phase 8 baseline: recorded whenever the
        // session actually ran (completed OR failed on a real step, not only
        // a fully clean completion - see InstallController::actionRun's
        // matching comment for why a partial install still needs a
        // baseline). Read fresh from disk rather than trusting
        // $final->blueprint's in-memory copy, since manifest.json's version
        // lives in a separate file from blueprint.json.
        if ($final->fatalError === null) {
            $catalog = Site7Studio::getInstance()->starterKitCatalog;
            $version = $catalog->getManifestVersion($final->starterKitHandle) ?? '0.0.0';
            Site7Studio::getInstance()->installedStarterKits->recordInstall($final->starterKitHandle, $version, $final->blueprint ?? []);
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Installing Starter Kit (session ' . $this->sessionUid . ')';
    }
}
