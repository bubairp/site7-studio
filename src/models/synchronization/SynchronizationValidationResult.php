<?php

namespace site7\studio\models\synchronization;

/**
 * SynchronizationValidator's output (Website Starter Kit System Phase 8) -
 * mirrors Phase 6's ValidationResult shape exactly ({valid, errors,
 * warnings, checks}), kept as its own class rather than reusing that one
 * directly since a sync's compatibility questions (plugin version
 * satisfaction, Project Config drift, conflicts present) are a distinct
 * concern from a fresh install's. Never mutates anything - dry-run by
 * construction.
 */
final class SynchronizationValidationResult
{
    public function __construct(
        public readonly bool $valid,
        /** @var string[] */
        public readonly array $errors,
        /** @var string[] */
        public readonly array $warnings,
        /** @var array<int, array{name: string, passed: bool, detail: string}> */
        public readonly array $checks,
    ) {
    }

    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'checks' => $this->checks,
        ];
    }
}
