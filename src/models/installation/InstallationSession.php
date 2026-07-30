<?php

namespace site7\studio\models\installation;

/**
 * The complete lifecycle state of one Starter Kit installation attempt
 * (Website Starter Kit System Phase 7) - the single object the Fresh-Install
 * Setup Wizard, its queue job, and its console command all pass around
 * instead of any of them holding installation state of their own. Persisted
 * by InstallationSessionService between requests/processes, since a real
 * install can span several process boundaries (see $stagesCompleted below).
 *
 * A plain, mutable data holder - deliberately not a craft\base\Model (every
 * prior Model subclass in the installation namespace collided with a
 * yii\base\Model-reserved method name the first time it was live-tested; see
 * PHASE-6-INSTALLATION-ORCHESTRATION.md). It contains no business logic:
 * InstallationStageRunner is the only thing that ever changes what an
 * installation *does*; this class only remembers what already happened.
 */
final class InstallationSession
{
    public const STATUS_CREATED = 'created';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const STEP_SELECT = 'select';
    public const STEP_VALIDATE = 'validate';
    public const STEP_PREVIEW = 'preview';
    public const STEP_EXECUTE = 'execute';
    public const STEP_SUMMARY = 'summary';

    /**
     * Fixed stage order InstallationOrchestratorService walks through, one
     * fresh OS process per stage - 'composer' is always its own stage
     * because Craft caches its Composer-plugin manifest once per process
     * (see PHASE-6-INSTALLATION-ORCHESTRATION.md).
     *
     * 'project-config' is also always its own final stage, discovered live
     * during this phase's own verification: rebuilding Project Config in the
     * same process as a plugin-install step that just ran intermittently
     * wrote the plugin back out as *not* enabled - the freshly-enabled
     * plugin's state hadn't fully propagated through Craft's own
     * project-config internals within that same request, so the rebuilt
     * config/project/project.yaml lost the enabled flag, and the next
     * request's automatic Project Config sync then actually disabled the
     * plugin again. Isolating 'project-config' into its own later process
     * (booting fresh, after plugin-install's DB/config writes are fully
     * committed) resolved it - the same "state written earlier in this
     * process isn't reliably visible to something else in this same
     * process" class of bug as the Phase 6 composer/plugin-install finding,
     * just a second, independently-discovered instance of it.
     */
    public const STAGE_COMPOSER = 'composer';
    public const STAGE_INSTALL = 'install';
    public const STAGE_PROJECT_CONFIG = 'project-config';
    public const STAGE_ORDER = [self::STAGE_COMPOSER, self::STAGE_INSTALL, self::STAGE_PROJECT_CONFIG];

    public function __construct(
        public readonly string $uid,
        public readonly string $starterKitHandle,
        public readonly string $packagePath,
        public bool $dryRun = false,
        public string $status = self::STATUS_CREATED,
        public string $currentStep = self::STEP_SELECT,
        /** @var array|null Blueprint (Phase 5), read once from the package's own blueprint.json at session creation. */
        public ?array $blueprint = null,
        /** @var array|null ValidationResult::toArray(), refreshed every time a stage is (re-)planned. */
        public ?array $validationResult = null,
        /** @var array|null InstallationPlan::toArray() - display/preview only, never replayed verbatim across a process boundary (each stage re-plans fresh; see InstallationStageRunner). */
        public ?array $plan = null,
        /** @var string[] Stage keys already executed (subset of STAGE_ORDER), driving which stage runs next. */
        public array $stagesCompleted = [],
        /**
         * @var array<int, array{completedSteps: array, skippedSteps: array, failedSteps: array, errors: string[]}>
         * One entry per stage actually executed, merged into a final
         * InstallationReport once the last stage finishes. Deliberately
         * excludes warnings - InstallationExecutor::execute() always returns
         * the full ValidationResult's own warnings verbatim on every call,
         * so re-collecting them per stage would duplicate them; the final
         * report's warnings come straight from $validationResult instead.
         */
        public array $stageResults = [],
        /** @var array<int, array{timestamp: string, type: string, key: string, label: string, status: string, message: ?string}> Flat, append-only step-level log for CP polling/live progress. */
        public array $progressLog = [],
        /** Set only on an orchestration-level failure (a subprocess that couldn't even start, crashed, or timed out) - distinct from a normal failed InstallationStep, which lives in $stageResults/$progressLog instead. */
        public ?string $fatalError = null,
        public string $dateCreated = '',
        public string $dateUpdated = '',
    ) {
    }

    public function isDone(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function nextStage(): ?string
    {
        foreach (self::STAGE_ORDER as $stage) {
            if (!in_array($stage, $this->stagesCompleted, true)) {
                return $stage;
            }
        }
        return null;
    }

    public function appendLog(string $type, string $key, string $label, string $status, ?string $message): void
    {
        $this->progressLog[] = [
            'timestamp' => date(DATE_ATOM),
            'type' => $type,
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'message' => $message,
        ];
    }

    /**
     * @return array{completedSteps: array, skippedSteps: array, failedSteps: array, errors: string[]}
     */
    public function mergedStageResults(): array
    {
        $merged = ['completedSteps' => [], 'skippedSteps' => [], 'failedSteps' => [], 'errors' => []];
        foreach ($this->stageResults as $result) {
            foreach ($merged as $key => $_) {
                $merged[$key] = array_merge($merged[$key], $result[$key] ?? []);
            }
        }
        return $merged;
    }

    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'starterKitHandle' => $this->starterKitHandle,
            'packagePath' => $this->packagePath,
            'dryRun' => $this->dryRun,
            'status' => $this->status,
            'currentStep' => $this->currentStep,
            'blueprint' => $this->blueprint,
            'validationResult' => $this->validationResult,
            'plan' => $this->plan,
            'stagesCompleted' => $this->stagesCompleted,
            'stageResults' => $this->stageResults,
            'progressLog' => $this->progressLog,
            'fatalError' => $this->fatalError,
            'dateCreated' => $this->dateCreated,
            'dateUpdated' => $this->dateUpdated,
        ];
    }

    public static function fromArray(array $data): self
    {
        $session = new self(
            $data['uid'],
            $data['starterKitHandle'],
            $data['packagePath'],
            $data['dryRun'] ?? false,
            $data['status'] ?? self::STATUS_CREATED,
            $data['currentStep'] ?? self::STEP_SELECT,
            $data['blueprint'] ?? null,
            $data['validationResult'] ?? null,
            $data['plan'] ?? null,
            $data['stagesCompleted'] ?? [],
            $data['stageResults'] ?? [],
            $data['progressLog'] ?? [],
            $data['fatalError'] ?? null,
            $data['dateCreated'] ?? '',
            $data['dateUpdated'] ?? '',
        );

        return $session;
    }
}
