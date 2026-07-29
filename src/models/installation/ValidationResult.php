<?php

namespace site7\studio\models\installation;

/**
 * InstallationValidator's output (Website Starter Kit System Phase 6) - a
 * pure read-only report of whether an install would likely succeed:
 * Blueprint integrity, dependency availability, environment compatibility,
 * missing prerequisites. Never mutates anything; safe to run any number of
 * times (dry-run by definition).
 */
final class ValidationResult
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
