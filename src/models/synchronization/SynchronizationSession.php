<?php

namespace site7\studio\models\synchronization;

/**
 * The complete lifecycle state of one synchronization attempt (Website
 * Starter Kit System Phase 8) - the Update Wizard's counterpart to Phase
 * 7's InstallationSession, persisted the same way (one JSON blob per uid)
 * so it survives the same process boundaries. Deliberately does not carry
 * its own execution machinery: once confirmed, $installationSessionUid
 * points at a real InstallationSession that SynchronizationOrchestratorService
 * drives via the existing, unchanged InstallationOrchestratorService/queue/
 * console entry points - this class only remembers the sync-specific
 * decisions (the plan, the validation, which removals were confirmed) that
 * wrap around that reused machinery.
 */
final class SynchronizationSession
{
    public const STATUS_CREATED = 'created';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly string $uid,
        public readonly string $packageHandle,
        public readonly string $packagePath,
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public bool $dryRun = false,
        public string $status = self::STATUS_CREATED,
        public ?array $oldBlueprint = null,
        public ?array $newBlueprint = null,
        /** @var array|null SynchronizationPlan::toArray() */
        public ?array $plan = null,
        /** @var array|null SynchronizationValidationResult::toArray() */
        public ?array $validationResult = null,
        /** @var string[] resource keys (from plan.removals) the user explicitly confirmed - opt-in, one per key */
        public array $confirmedRemovalKeys = [],
        /** The real InstallationSession created to apply $plan's create/update steps, once execution starts. */
        public ?string $installationSessionUid = null,
        /**
         * @var array<int, array{step: array, message: ?string}>|null Outcome of
         * applying $confirmedRemovalKeys, filled in by a dedicated subprocess
         * (see SynchronizationOrchestratorService's docblock for why removal
         * can't run in the same process that just called
         * InstallationOrchestratorService).
         */
        public ?array $appliedRemovals = null,
        /** @var array|null SynchronizationReport::toArray(), once execution finishes */
        public ?array $report = null,
        public ?string $fatalError = null,
        public string $dateCreated = '',
        public string $dateUpdated = '',
    ) {
    }

    public function isDone(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'packageHandle' => $this->packageHandle,
            'packagePath' => $this->packagePath,
            'fromVersion' => $this->fromVersion,
            'toVersion' => $this->toVersion,
            'dryRun' => $this->dryRun,
            'status' => $this->status,
            'oldBlueprint' => $this->oldBlueprint,
            'newBlueprint' => $this->newBlueprint,
            'plan' => $this->plan,
            'validationResult' => $this->validationResult,
            'confirmedRemovalKeys' => $this->confirmedRemovalKeys,
            'installationSessionUid' => $this->installationSessionUid,
            'appliedRemovals' => $this->appliedRemovals,
            'report' => $this->report,
            'fatalError' => $this->fatalError,
            'dateCreated' => $this->dateCreated,
            'dateUpdated' => $this->dateUpdated,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['uid'],
            $data['packageHandle'],
            $data['packagePath'],
            $data['fromVersion'],
            $data['toVersion'],
            $data['dryRun'] ?? false,
            $data['status'] ?? self::STATUS_CREATED,
            $data['oldBlueprint'] ?? null,
            $data['newBlueprint'] ?? null,
            $data['plan'] ?? null,
            $data['validationResult'] ?? null,
            $data['confirmedRemovalKeys'] ?? [],
            $data['installationSessionUid'] ?? null,
            $data['appliedRemovals'] ?? null,
            $data['report'] ?? null,
            $data['fatalError'] ?? null,
            $data['dateCreated'] ?? '',
            $data['dateUpdated'] ?? '',
        );
    }
}
