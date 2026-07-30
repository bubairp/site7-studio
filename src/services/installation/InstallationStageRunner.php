<?php

namespace site7\studio\services\installation;

use craft\base\Component;
use site7\studio\models\installation\InstallationPlan;
use site7\studio\models\installation\InstallationSession;
use site7\studio\models\installation\InstallationStep;

/**
 * Runs exactly one InstallationSession stage per call (Website Starter Kit
 * System Phase 7) - never a whole installation start-to-finish. Contains no
 * installation logic of its own: every stage re-plans via the existing
 * InstallationPlanner and re-validates via the existing InstallationValidator
 * (both cheap, read-only, and proven deterministic - see
 * PHASE-6-INSTALLATION-ORCHESTRATION.md's "Planning is deterministic"
 * finding), then hands a filtered InstallationPlan to the existing
 * InstallationExecutor unchanged.
 *
 * Three stages, each its own OS process (see InstallationOrchestratorService):
 * 'composer' alone, then 'install' (plugin-install/craft-resource/content/
 * frontend/npm), then 'project-config' alone. Both boundaries exist because
 * of state that doesn't reliably propagate to something else running later
 * in the *same* PHP process: composer/plugin-install per Craft's own
 * Composer-plugin manifest caching (Phase 6's live-testing finding), and
 * plugin-install/project-config per this phase's own live-testing finding
 * (rebuilding Project Config right after enabling a plugin in the same
 * process intermittently wrote that plugin back out as not enabled). This
 * class only decides *what* belongs in each stage; InstallationOrchestratorService
 * is the thing that actually puts a process boundary between them.
 */
class InstallationStageRunner extends Component
{
    /**
     * Step *types* whose failure stops the whole run - mirrors
     * InstallationExecutor::CRITICAL_TYPES exactly (that constant is
     * private, so it's duplicated here rather than exposed just for this).
     * Checked per failed step, not per stage: STAGE_INSTALL bundles
     * critical (plugin-install) and non-critical (craft-resource/content/
     * frontend/npm) step types together, and a failed content step must
     * NOT block the later project-config stage from running, matching
     * InstallationExecutor's own single-process behavior exactly.
     */
    private const CRITICAL_TYPES = [InstallationStep::TYPE_COMPOSER, InstallationStep::TYPE_PLUGIN_INSTALL];

    public ?InstallationPlanner $planner = null;
    public ?InstallationValidator $validator = null;
    public ?InstallationExecutor $executor = null;
    public ?InstallationSessionService $sessions = null;

    public function init(): void
    {
        parent::init();
        $this->planner ??= new InstallationPlanner();
        $this->validator ??= new InstallationValidator();
        $this->executor ??= new InstallationExecutor();
        $this->sessions ??= new InstallationSessionService();
    }

    /**
     * @return array{hasNextStage: bool, fatal: bool}
     */
    public function runNextStage(InstallationSession $session): array
    {
        if ($session->isDone()) {
            return ['hasNextStage' => false, 'fatal' => $session->status === InstallationSession::STATUS_FAILED];
        }

        if (empty($session->blueprint)) {
            $session->status = InstallationSession::STATUS_FAILED;
            $session->fatalError = 'Session has no Blueprint to install - the Starter Kit package may be missing its blueprint.json.';
            $this->sessions->save($session);
            return ['hasNextStage' => false, 'fatal' => true];
        }

        $plan = $this->planner->plan($session->blueprint, $session->packagePath);
        $validationResult = $this->validator->validateInstallation($session->blueprint, $plan);

        $session->plan = $plan->toArray();
        $session->validationResult = $validationResult->toArray();
        $session->status = InstallationSession::STATUS_EXECUTING;
        $this->sessions->save($session);

        if (!$validationResult->valid) {
            $session->status = InstallationSession::STATUS_FAILED;
            $session->fatalError = 'Validation failed - see validationResult.errors.';
            $this->sessions->save($session);
            return ['hasNextStage' => false, 'fatal' => true];
        }

        // Skip any stage with nothing to do in this same call rather than
        // spending a whole subprocess hop on it - the common case for
        // 'composer' on a re-install (see
        // PHASE-6-INSTALLATION-ORCHESTRATION.md's "Idempotency" finding),
        // and for 'project-config' when nothing upstream actually changed
        // anything (dry runs, or a plan with only already-satisfied steps).
        $stage = $session->nextStage();
        while ($stage !== null && empty($this->stepsForStage($plan, $stage))) {
            $session->stagesCompleted[] = $stage;
            $stage = $session->nextStage();
        }

        if ($stage === null) {
            $session->status = InstallationSession::STATUS_COMPLETED;
            $this->sessions->save($session);
            return ['hasNextStage' => false, 'fatal' => false];
        }

        $stagePlan = new InstallationPlan($this->stepsForStage($plan, $stage), $plan->dependencyValidation);

        $onProgress = function (InstallationStep $step, string $status, ?string $message) use ($session) {
            $session->appendLog($step->type, $step->key, $step->label, $status, $message);
            $this->sessions->save($session);
        };

        $report = $this->executor->execute($stagePlan, $validationResult, $session->dryRun, $onProgress);

        $session->stageResults[] = [
            'completedSteps' => $report->completedSteps,
            'skippedSteps' => $report->skippedSteps,
            'failedSteps' => $report->failedSteps,
            'errors' => $report->errors,
        ];
        $session->stagesCompleted[] = $stage;

        $criticalFailure = false;
        foreach ($report->failedSteps as $failed) {
            if (in_array($failed['step']['type'], self::CRITICAL_TYPES, true)) {
                $criticalFailure = true;
                break;
            }
        }

        // Also skip any later stage that turns out to have nothing to do -
        // e.g. a plan with no project-config step at all shouldn't cost a
        // whole extra subprocess hop just to discover that.
        $nextStage = $criticalFailure ? null : $session->nextStage();
        while ($nextStage !== null && empty($this->stepsForStage($plan, $nextStage))) {
            $session->stagesCompleted[] = $nextStage;
            $nextStage = $session->nextStage();
        }

        if ($criticalFailure || $nextStage === null) {
            $merged = $session->mergedStageResults();
            $session->status = empty($merged['failedSteps']) ? InstallationSession::STATUS_COMPLETED : InstallationSession::STATUS_FAILED;
            $this->sessions->save($session);
            return ['hasNextStage' => false, 'fatal' => false];
        }

        $this->sessions->save($session);
        return ['hasNextStage' => true, 'fatal' => false];
    }

    /** @return InstallationStep[] */
    private function stepsForStage(InstallationPlan $plan, string $stage): array
    {
        return match ($stage) {
            InstallationSession::STAGE_COMPOSER => $plan->stepsOfType(InstallationStep::TYPE_COMPOSER),
            InstallationSession::STAGE_PROJECT_CONFIG => $plan->stepsOfType(InstallationStep::TYPE_PROJECT_CONFIG),
            InstallationSession::STAGE_INSTALL => array_values(array_filter(
                $plan->steps,
                fn(InstallationStep $s) => !in_array($s->type, [InstallationStep::TYPE_COMPOSER, InstallationStep::TYPE_PROJECT_CONFIG], true)
            )),
            default => [],
        };
    }
}
