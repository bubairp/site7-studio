<?php

namespace site7\studio\models\installation;

/**
 * InstallationExecutor's final output (Website Starter Kit System Phase 6):
 * exactly which steps completed, which were skipped (and why - already
 * satisfied, or a soft prerequisite failure), which produced warnings, and
 * which failed outright, alongside the ValidationResult the execution
 * started from. $success reflects whether execution reached the end
 * without an unrecoverable failure - it does not require every step to
 * have completed (a skipped step is not a failure).
 */
final class InstallationReport
{
    public function __construct(
        public readonly bool $success,
        /** @var array<int, array{step: array, message: ?string}> */
        public readonly array $completedSteps,
        /** @var array<int, array{step: array, message: ?string}> */
        public readonly array $skippedSteps,
        /** @var array<int, array{step: array, message: ?string}> */
        public readonly array $failedSteps,
        /** @var string[] */
        public readonly array $warnings,
        /** @var string[] */
        public readonly array $errors,
        public readonly ValidationResult $validationResult,
    ) {
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'completedSteps' => $this->completedSteps,
            'skippedSteps' => $this->skippedSteps,
            'failedSteps' => $this->failedSteps,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'validationResult' => $this->validationResult->toArray(),
        ];
    }
}
