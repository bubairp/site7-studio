<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;
use site7\studio\jobs\InstallStarterKitJob;
use site7\studio\models\installation\InstallationSession;

/**
 * The Fresh-Install Setup Wizard's CP presentation layer (Website Starter
 * Kit System Phase 7). Every action here only reads or persists an
 * InstallationSession and calls the existing
 * InstallationPlanner/InstallationValidator services, or pushes a queue job -
 * it never runs InstallationExecutor and never contains installation logic
 * of its own. See InstallationStageRunner/InstallationOrchestratorService
 * for where an install actually happens.
 */
class InstallWizardController extends Controller
{
    /** Step 1 - list available Starter Kits. */
    public function actionIndex()
    {
        $kits = Site7Studio::getInstance()->starterKitCatalog->listAvailable();

        return $this->renderTemplate('site7-studio/install-wizard/index', [
            'kits' => $kits,
        ]);
    }

    /**
     * Step 2/3 - validate a Starter Kit and build its InstallationPlan for
     * preview, creating the InstallationSession the rest of the wizard will
     * carry forward. Entirely read-only - safe to call any number of times,
     * including as an explicit "Dry Run" before Step 4.
     */
    public function actionValidate()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $handle = $request->getRequiredBodyParam('handle');
        $dryRun = (bool)$request->getBodyParam('dryRun', false);

        $catalog = Site7Studio::getInstance()->starterKitCatalog;
        $blueprint = $catalog->getBlueprint($handle);

        if ($blueprint === null) {
            return $this->asJson(['success' => false, 'error' => "No blueprint.json found for '{$handle}'."]);
        }

        $packagePath = $catalog->packagePath($handle);
        $plan = Site7Studio::getInstance()->installationPlanner->plan($blueprint, $packagePath);
        $validationResult = Site7Studio::getInstance()->installationValidator->validateInstallation($blueprint, $plan);

        $session = Site7Studio::getInstance()->installationSessions->create($handle, $packagePath, $blueprint, $dryRun);
        $session->plan = $plan->toArray();
        $session->validationResult = $validationResult->toArray();
        $session->status = InstallationSession::STATUS_VALIDATED;
        $session->currentStep = InstallationSession::STEP_PREVIEW;
        Site7Studio::getInstance()->installationSessions->save($session);

        return $this->asJson([
            'success' => true,
            'sessionUid' => $session->uid,
            'validationResult' => $validationResult->toArray(),
            'plan' => $plan->toArray(),
        ]);
    }

    /** Step 3 - re-display an already-built plan/validation for a session (e.g. after a page reload). */
    public function actionPreview(string $sessionUid)
    {
        $session = $this->requireSession($sessionUid);

        return $this->renderTemplate('site7-studio/install-wizard/preview', [
            'session' => $session,
        ]);
    }

    /**
     * Step 4 - queue the install. Never executes anything in this request:
     * only pushes InstallStarterKitJob and returns immediately, so the CP
     * stays responsive regardless of how long the real install takes.
     */
    public function actionExecute()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $sessionUid = Craft::$app->getRequest()->getRequiredBodyParam('sessionUid');
        $session = $this->requireSession($sessionUid);

        $session->status = InstallationSession::STATUS_QUEUED;
        $session->currentStep = InstallationSession::STEP_EXECUTE;
        Site7Studio::getInstance()->installationSessions->save($session);

        Craft::$app->getQueue()->push(new InstallStarterKitJob(['sessionUid' => $sessionUid]));

        return $this->asJson(['success' => true, 'sessionUid' => $sessionUid]);
    }

    /** Step 4 - polled by the wizard's JS while the queued job runs. */
    public function actionProgress(string $sessionUid)
    {
        $this->requireAcceptsJson();

        $session = $this->requireSession($sessionUid);

        return $this->asJson([
            'success' => true,
            'status' => $session->status,
            'currentStep' => $session->currentStep,
            'stagesCompleted' => $session->stagesCompleted,
            'totalStages' => count(InstallationSession::STAGE_ORDER),
            'progressLog' => $session->progressLog,
            'fatalError' => $session->fatalError,
            'done' => $session->isDone(),
        ]);
    }

    /** Step 5 - final summary once the session is done. */
    public function actionSummary(string $sessionUid)
    {
        $session = $this->requireSession($sessionUid);

        $merged = $session->mergedStageResults();

        return $this->renderTemplate('site7-studio/install-wizard/summary', [
            'session' => $session,
            'report' => $merged,
        ]);
    }

    private function requireSession(string $sessionUid): InstallationSession
    {
        $session = Site7Studio::getInstance()->installationSessions->loadSession($sessionUid);
        if ($session === null) {
            throw new \yii\web\NotFoundHttpException("No installation session found for {$sessionUid}.");
        }
        return $session;
    }
}
