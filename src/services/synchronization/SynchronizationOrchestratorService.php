<?php

namespace site7\studio\services\synchronization;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use Symfony\Component\Process\Process;
use site7\studio\models\installation\InstallationSession;
use site7\studio\models\synchronization\Conflict;
use site7\studio\models\synchronization\SynchronizationReport;
use site7\studio\models\synchronization\SynchronizationSession;
use site7\studio\models\synchronization\SynchronizationValidationResult;
use site7\studio\services\ComposerDependencyScanner;
use site7\studio\services\installation\InstallationOrchestratorService;
use site7\studio\services\installation\InstallationSessionService;

/**
 * Applies a confirmed SynchronizationPlan (Website Starter Kit System
 * Phase 8) - contains no create/update installation logic of its own.
 * Every create/update step is applied by literally re-running Phase 7's
 * entire install machinery (InstallationSession + InstallationOrchestratorService,
 * completely unchanged) against the *new* Blueprint - already idempotent by
 * design (see PHASE-6-INSTALLATION-ORCHESTRATION.md's "Idempotency"
 * finding), so replaying it naturally creates what's new and updates what
 * changed without this class reimplementing either.
 *
 * Two critical details found by this phase's own live testing:
 *
 * 1. Phase 6's InstallationPlanner has no concept of "this resource is in
 *    conflict" - left unchecked, replaying the *raw* new Blueprint would
 *    silently overwrite exactly the locally-modified resource
 *    SynchronizationPlanner just refused to touch, defeating the entire
 *    point of conflict detection. buildExecutableBlueprint() strips every
 *    conflicted resource out of the Blueprint handed to InstallationSession,
 *    so the reused Phase 6/7 machinery never even sees it.
 * 2. Opt-in removal (a capability Phase 6/7 never had) cannot run in this
 *    same process right after InstallationOrchestratorService returns:
 *    Project Config was just rebuilt by one of that call's own subprocesses,
 *    and this process's own in-memory Craft::$app still has the
 *    project-config snapshot from when *it* booted - attempting a removal
 *    here failed live with "The loaded project config is out-of-date."
 *    Removal is therefore applied via its own freshly-spawned subprocess
 *    too (`site7-studio/update/apply-removals`), the same "state written
 *    earlier in this process isn't reliably visible to something else in
 *    this same process" class of bug InstallationOrchestratorService's own
 *    docblock already describes, just a third independently-discovered
 *    instance of it.
 */
class SynchronizationOrchestratorService extends Component
{
    private const REMOVAL_TIMEOUT_SECONDS = 300;

    public ?InstallationSessionService $installationSessions = null;
    public ?InstallationOrchestratorService $installationOrchestrator = null;
    public ?SynchronizationSessionService $sessions = null;
    public ?SynchronizationHistoryService $history = null;
    public ?InstalledStarterKitTrackingService $tracking = null;

    public function init(): void
    {
        parent::init();
        $this->installationSessions ??= new InstallationSessionService();
        $this->installationOrchestrator ??= new InstallationOrchestratorService();
        $this->sessions ??= new SynchronizationSessionService();
        $this->history ??= new SynchronizationHistoryService();
        $this->tracking ??= new InstalledStarterKitTrackingService();
    }

    /**
     * @param callable(InstallationSession): void|null $onInstallProgress forwarded to the underlying InstallationOrchestratorService
     */
    public function execute(SynchronizationSession $session, ?callable $onInstallProgress = null): SynchronizationReport
    {
        $executableBlueprint = $this->buildExecutableBlueprint($session->newBlueprint, $session->plan['conflicts'] ?? []);

        $installationSession = $this->installationSessions->create(
            $session->packageHandle,
            $session->packagePath,
            $executableBlueprint,
            $session->dryRun
        );
        $session->installationSessionUid = $installationSession->uid;
        $this->sessions->save($session);

        $finalInstallSession = $this->installationOrchestrator->runToCompletion($installationSession->uid, $onInstallProgress);
        $installReport = $finalInstallSession->mergedStageResults();

        $appliedRemovals = [];
        if (!$session->dryRun && !empty($session->confirmedRemovalKeys)) {
            $this->sessions->save($session);
            $this->runRemovalSubprocess($session->uid);
            $reloaded = $this->sessions->loadSession($session->uid);
            $appliedRemovals = $reloaded?->appliedRemovals ?? [];
        }
        $session->appliedRemovals = $appliedRemovals;

        $unresolvedConflicts = $session->plan['conflicts'] ?? [];
        $success = $finalInstallSession->status === InstallationSession::STATUS_COMPLETED && empty($installReport['failedSteps']);

        // Whether to advance the tracked baseline/history is deliberately
        // more lenient than $success (the report's own strict pass/fail) -
        // mirrors InstallController's own "fatalError === null" leniency
        // from Phase 7: a real Blueprint (content included) can have a
        // non-critical step fail (e.g. content, which this phase never
        // auto-applies anyway - see SynchronizationPlanner's docblock) while
        // every craft-resource/plugin/frontend change still applied
        // correctly. Refusing to ever advance the baseline in that case
        // would make every later sync re-diff against a permanently stale
        // snapshot. $ranAtAll only requires that execution was actually
        // attempted (not short-circuited by a missing Blueprint).
        $ranAtAll = $finalInstallSession->fatalError === null;

        $report = new SynchronizationReport(
            $success,
            $installReport['completedSteps'] ?? [],
            $installReport['skippedSteps'] ?? [],
            $installReport['failedSteps'] ?? [],
            $appliedRemovals,
            array_map(
                fn(array $c) => new Conflict($c['type'], $c['resourceKey'], $c['description'], $c['installedValue'], $c['newValue']),
                $unresolvedConflicts
            ),
            $finalInstallSession->validationResult['warnings'] ?? [],
            $finalInstallSession->fatalError ? [$finalInstallSession->fatalError] : ($installReport['errors'] ?? []),
            new SynchronizationValidationResult(
                $session->validationResult['valid'] ?? true,
                $session->validationResult['errors'] ?? [],
                $session->validationResult['warnings'] ?? [],
                $session->validationResult['checks'] ?? []
            )
        );

        if (!$session->dryRun) {
            $this->history->recordResult($session->packageHandle, $session->fromVersion, $session->toVersion, $success ? 'completed' : 'failed', $report->toArray());
            if ($ranAtAll) {
                $appliedSnapshot = $this->buildAppliedBlueprintSnapshot($session->oldBlueprint ?? [], $session->newBlueprint ?? [], $unresolvedConflicts, $session->confirmedRemovalKeys);
                $this->tracking->recordSync($session->packageHandle, $session->toVersion, $appliedSnapshot);
            }
        }

        $session->report = $report->toArray();
        $session->status = $success ? SynchronizationSession::STATUS_COMPLETED : SynchronizationSession::STATUS_FAILED;

        return $report;
    }

    /** Spawns exactly one `php craft site7-studio/update/apply-removals <uid>` subprocess and waits for it to exit. */
    private function runRemovalSubprocess(string $sessionUid): void
    {
        $phpExecutable = App::phpExecutable();
        $craftScript = (new ComposerDependencyScanner())->projectRoot() . '/craft';

        if (!$phpExecutable || !is_file($craftScript)) {
            return;
        }

        $process = new Process([$phpExecutable, $craftScript, 'site7-studio/update/apply-removals', $sessionUid]);
        $process->setTimeout(self::REMOVAL_TIMEOUT_SECONDS);
        $process->run();

        if (!$process->isSuccessful()) {
            Craft::warning(
                "Removal subprocess for sync session {$sessionUid} exited with code {$process->getExitCode()}: {$process->getErrorOutput()}",
                'site7-studio'
            );
        }
    }

    /**
     * The Blueprint snapshot recorded as the new installed baseline is
     * deliberately not just $newBlueprint verbatim: a conflicted resource
     * was never actually touched (still at its old captured definition,
     * live-modified or not), and a proposed removal the user didn't
     * explicitly confirm is still sitting there too. Recording the raw new
     * Blueprint as "what's installed" would make the next sync's diff
     * silently forget both - the conflict would vanish (old == new baseline
     * now) and the un-confirmed removal would stop being offered at all.
     * This reconstructs a baseline that reflects what was *actually*
     * applied: the new Blueprint's own values, except conflicted resources
     * (kept at their old captured value) and un-confirmed removals (kept,
     * not dropped).
     */
    private function buildAppliedBlueprintSnapshot(array $oldBlueprint, array $newBlueprint, array $conflicts, array $confirmedRemovalKeys): array
    {
        $conflictedKeys = array_column($conflicts, 'resourceKey');
        $oldResourcesByKind = $oldBlueprint['resources'] ?? [];
        $snapshot = $newBlueprint;

        foreach (['craftSections', 'assetVolumes', 'categoryGroups', 'tagGroups'] as $kind) {
            $newItems = $snapshot['resources'][$kind] ?? [];
            $oldItems = $oldResourcesByKind[$kind] ?? [];
            $newHandles = array_column($newItems, 'handle');

            // Conflicted resources: replace the new value with the old one - it was never actually applied.
            foreach ($newItems as $i => $item) {
                if (in_array("{$kind}:{$item['handle']}", $conflictedKeys, true)) {
                    foreach ($oldItems as $oldItem) {
                        if ($oldItem['handle'] === $item['handle']) {
                            $newItems[$i] = $oldItem;
                            break;
                        }
                    }
                }
            }

            // Dropped resources not explicitly confirmed for removal: keep tracking them at their old value.
            foreach ($oldItems as $oldItem) {
                $key = "{$kind}:{$oldItem['handle']}";
                $wasDropped = !in_array($oldItem['handle'], $newHandles, true);
                if ($wasDropped && !in_array($key, $confirmedRemovalKeys, true)) {
                    $newItems[] = $oldItem;
                }
            }

            $snapshot['resources'][$kind] = $newItems;
        }

        return $snapshot;
    }

    /**
     * Removes every conflicted resource (by "{kind}:{handle}" resourceKey,
     * matching SynchronizationPlanner's own key format) from the Blueprint
     * before it's ever handed to InstallationSession - see this class's
     * docblock for why this isn't optional.
     */
    private function buildExecutableBlueprint(array $blueprint, array $conflicts): array
    {
        $conflictedKeys = array_column($conflicts, 'resourceKey');
        if (empty($conflictedKeys) || empty($blueprint['resources'])) {
            return $blueprint;
        }

        foreach (['craftSections', 'assetVolumes', 'categoryGroups', 'tagGroups'] as $kind) {
            if (empty($blueprint['resources'][$kind])) {
                continue;
            }
            $blueprint['resources'][$kind] = array_values(array_filter(
                $blueprint['resources'][$kind],
                fn(array $item) => !in_array("{$kind}:{$item['handle']}", $conflictedKeys, true)
            ));
        }

        return $blueprint;
    }
}
