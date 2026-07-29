<?php

namespace site7\studio\models\installation;

/**
 * InstallationPlanner's output (Website Starter Kit System Phase 6): a
 * deterministic, ordered list of InstallationSteps plus the dependency
 * validation the planner performed while deciding what's actually needed
 * against the current target environment (e.g. a plugin already required
 * in the target's composer.json gets no `composer` step). Building this
 * plan never installs, runs, or mutates anything - see InstallationExecutor
 * for that.
 *
 * Step order is fixed and matches the phase's own dependency sequence:
 * plugin-install -> composer -> npm -> craft-resource -> content ->
 * frontend -> project-config. Steps of the same type preserve the order
 * InstallationPlanner added them in (itself deterministic, since it walks
 * the Blueprint's own already-ordered lists).
 */
final class InstallationPlan
{
    public function __construct(
        /** @var InstallationStep[] */
        public readonly array $steps,
        /** @var array{errors: string[], warnings: string[]} */
        public readonly array $dependencyValidation,
    ) {
    }

    /** @return InstallationStep[] */
    public function stepsOfType(string $type): array
    {
        return array_values(array_filter($this->steps, fn(InstallationStep $s) => $s->type === $type));
    }

    public function toArray(): array
    {
        return [
            'steps' => array_map(fn(InstallationStep $s) => $s->toArray(), $this->steps),
            'dependencyValidation' => $this->dependencyValidation,
        ];
    }
}
