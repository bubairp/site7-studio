<?php

namespace site7\studio\models\synchronization;

/**
 * The final output of a synchronization run (Website Starter Kit System
 * Phase 8) - merges the underlying InstallationReport (the create/update
 * portion, executed by reusing Phase 6/7's InstallationExecutor/session
 * machinery unchanged against the newer Blueprint) with this phase's own
 * opt-in removal outcomes and whichever conflicts were never resolved.
 */
final class SynchronizationReport
{
    public function __construct(
        public readonly bool $success,
        /** @var array<int, array{step: array, message: ?string}> from the underlying InstallationReport */
        public readonly array $completedSteps,
        /** @var array<int, array{step: array, message: ?string}> */
        public readonly array $skippedSteps,
        /** @var array<int, array{step: array, message: ?string}> */
        public readonly array $failedSteps,
        /** @var array<int, array{step: array, message: ?string}> resources actually removed, opt-in only */
        public readonly array $appliedRemovals,
        /** @var Conflict[] carried over from the plan - never auto-resolved by executing */
        public readonly array $unresolvedConflicts,
        /** @var string[] */
        public readonly array $warnings,
        /** @var string[] */
        public readonly array $errors,
        public readonly SynchronizationValidationResult $validationResult,
    ) {
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'completedSteps' => $this->completedSteps,
            'skippedSteps' => $this->skippedSteps,
            'failedSteps' => $this->failedSteps,
            'appliedRemovals' => $this->appliedRemovals,
            'unresolvedConflicts' => array_map(fn(Conflict $c) => $c->toArray(), $this->unresolvedConflicts),
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'validationResult' => $this->validationResult->toArray(),
        ];
    }
}
