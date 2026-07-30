<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;
use site7\studio\jobs\SyncStarterKitJob;
use site7\studio\models\synchronization\SynchronizationSession;

/**
 * The Update Wizard's CP presentation layer (Website Starter Kit System
 * Phase 8) - mirrors InstallWizardController exactly. Every action here only
 * reads or persists a SynchronizationSession and calls the existing
 * SynchronizationPlanner/SynchronizationValidator services, or pushes a
 * queue job - it never runs SynchronizationOrchestratorService in this
 * request and never contains diff/execution logic of its own.
 */
class UpdateWizardController extends Controller
{
    /** Step 1 - list installed Starter Kits with a newer version available. */
    public function actionIndex()
    {
        $updates = Site7Studio::getInstance()->updateCatalog->listAvailableUpdates();

        return $this->renderTemplate('site7-studio/update-wizard/index', [
            'updates' => $updates,
        ]);
    }

    /**
     * Step 2/3 - build the SynchronizationPlan + compatibility checks for a
     * selected update, creating the SynchronizationSession the rest of the
     * wizard carries forward. Entirely read-only.
     */
    public function actionPlan()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $handle = $request->getRequiredBodyParam('handle');
        $dryRun = (bool)$request->getBodyParam('dryRun', false);

        $plugin = Site7Studio::getInstance();
        $installed = $plugin->installedStarterKits->getInstalled($handle);
        if ($installed === null) {
            return $this->asJson(['success' => false, 'error' => "'{$handle}' is not tracked as installed."]);
        }

        $newBlueprint = $plugin->starterKitCatalog->getBlueprint($handle);
        if ($newBlueprint === null) {
            return $this->asJson(['success' => false, 'error' => "No blueprint.json found for '{$handle}'."]);
        }

        $toVersion = $plugin->starterKitCatalog->getManifestVersion($handle) ?? $installed['installedVersion'];
        $plan = $plugin->synchronizationPlanner->plan($handle, $installed['installedVersion'], $toVersion, $installed['blueprint'], $newBlueprint);
        $validation = $plugin->synchronizationValidator->validateSynchronization($plan, null);

        $session = $plugin->synchronizationSessions->create(
            $handle,
            $plugin->starterKitCatalog->packagePath($handle),
            $installed['installedVersion'],
            $toVersion,
            $installed['blueprint'],
            $newBlueprint,
            $dryRun
        );
        $session->plan = $plan->toArray();
        $session->validationResult = $validation->toArray();
        $session->status = SynchronizationSession::STATUS_PLANNED;
        $plugin->synchronizationSessions->save($session);

        return $this->asJson([
            'success' => true,
            'sessionUid' => $session->uid,
            'plan' => $plan->toArray(),
            'validationResult' => $validation->toArray(),
        ]);
    }

    /**
     * Step 4 - confirm (optionally opting specific dropped resources into
     * removal) and queue the sync. Never executes anything in this request.
     */
    public function actionExecute()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $sessionUid = $request->getRequiredBodyParam('sessionUid');
        $confirmedRemovalKeys = (array)$request->getBodyParam('confirmedRemovalKeys', []);

        $session = $this->requireSession($sessionUid);
        $session->confirmedRemovalKeys = array_values(array_filter(array_map('strval', $confirmedRemovalKeys)));
        $session->status = SynchronizationSession::STATUS_EXECUTING;
        Site7Studio::getInstance()->synchronizationSessions->save($session);

        Craft::$app->getQueue()->push(new SyncStarterKitJob(['sessionUid' => $sessionUid]));

        return $this->asJson(['success' => true, 'sessionUid' => $sessionUid]);
    }

    /** Step 4 - polled by the wizard's JS while the queued job runs. Also surfaces the underlying InstallationSession's own step-by-step log. */
    public function actionProgress(string $sessionUid)
    {
        $this->requireAcceptsJson();

        $session = $this->requireSession($sessionUid);
        $progressLog = [];
        $stagesCompleted = [];
        $totalStages = 0;

        if ($session->installationSessionUid) {
            $installationSession = Site7Studio::getInstance()->installationSessions->loadSession($session->installationSessionUid);
            if ($installationSession) {
                $progressLog = $installationSession->progressLog;
                $stagesCompleted = $installationSession->stagesCompleted;
                $totalStages = count($installationSession::STAGE_ORDER);
            }
        }

        return $this->asJson([
            'success' => true,
            'status' => $session->status,
            'stagesCompleted' => $stagesCompleted,
            'totalStages' => $totalStages,
            'progressLog' => $progressLog,
            'fatalError' => $session->fatalError,
            'done' => $session->isDone(),
        ]);
    }

    /** Step 5 - final summary + this kit's own update history. */
    public function actionSummary(string $sessionUid)
    {
        $session = $this->requireSession($sessionUid);
        $history = Site7Studio::getInstance()->synchronizationHistory->historyFor($session->packageHandle);

        return $this->renderTemplate('site7-studio/update-wizard/summary', [
            'session' => $session,
            'history' => $history,
        ]);
    }

    private function requireSession(string $sessionUid): SynchronizationSession
    {
        $session = Site7Studio::getInstance()->synchronizationSessions->loadSession($sessionUid);
        if ($session === null) {
            throw new \yii\web\NotFoundHttpException("No synchronization session found for {$sessionUid}.");
        }
        return $session;
    }
}
