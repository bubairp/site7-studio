<?php

namespace site7\studio\interfaces;

use site7\studio\models\installation\InstallationStep;

/**
 * One dedicated executor per InstallationStep type (Website Starter Kit
 * System Phase 6) - InstallationExecutor delegates to whichever
 * implementation is registered for a step's type rather than containing
 * installation logic itself. Each implementation owns exactly one kind of
 * mutation (Composer, npm, Craft plugin install, Craft resource
 * create/update, captured content install, frontend file copy, Project
 * Config rebuild).
 */
interface StepExecutorInterface
{
    /**
     * @param InstallationStep[] $steps every step of this executor's own type, in plan order
     * @param bool $dryRun when true, must not mutate anything - only report what would happen
     * @return array<int, array{step: InstallationStep, status: 'completed'|'skipped'|'failed', message: ?string}>
     */
    public function execute(array $steps, bool $dryRun): array;
}
