<?php

namespace site7\studio\models\synchronization;

/**
 * SynchronizationPlanner's output (Website Starter Kit System Phase 8) - a
 * deterministic, read-only comparison between a previously-installed
 * Blueprint and a newer one, classified into what's safe to auto-apply
 * ($steps - handed to the existing InstallationExecutor unchanged, via a
 * real InstallationSession built from the new Blueprint), what's opt-in
 * ($removals - a resource the new Blueprint no longer wants, never deleted
 * without the user confirming that specific key), and what needs a human
 * ($conflicts - local modifications the plan refuses to overwrite).
 * Building this plan never reads or writes anything except live Craft
 * resource state (via the existing scanning/ scanners, read-only) - no
 * Craft resource is created, updated, or removed while planning.
 */
final class SynchronizationPlan
{
    public function __construct(
        public readonly string $packageHandle,
        public readonly string $fromVersion,
        public readonly string $toVersion,
        /** @var SynchronizationStep[] safe to auto-apply: creates and updates to resources with no local drift */
        public readonly array $steps,
        /** @var SynchronizationStep[] resources the new Blueprint no longer includes - opt-in, one confirmation per key */
        public readonly array $removals,
        /** @var Conflict[] */
        public readonly array $conflicts,
        /** @var array{added: array, updated: array, removed: array} */
        public readonly array $pluginChanges,
        /** @var array{npmAdded: array, npmRemoved: array, npmUpdated: array, configFilesChanged: bool} */
        public readonly array $frontendChanges,
        /** @var string[] informational notes only - no dependency graph is recomputed or applied here */
        public readonly array $dependencyChanges,
        /** @var string[] informational notes only */
        public readonly array $projectConfigChanges,
        /** @var string[] */
        public readonly array $warnings = [],
        /** @var string[] */
        public readonly array $errors = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'packageHandle' => $this->packageHandle,
            'fromVersion' => $this->fromVersion,
            'toVersion' => $this->toVersion,
            'steps' => array_map(fn(SynchronizationStep $s) => $s->toArray(), $this->steps),
            'removals' => array_map(fn(SynchronizationStep $s) => $s->toArray(), $this->removals),
            'conflicts' => array_map(fn(Conflict $c) => $c->toArray(), $this->conflicts),
            'pluginChanges' => $this->pluginChanges,
            'frontendChanges' => $this->frontendChanges,
            'dependencyChanges' => $this->dependencyChanges,
            'projectConfigChanges' => $this->projectConfigChanges,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }
}
