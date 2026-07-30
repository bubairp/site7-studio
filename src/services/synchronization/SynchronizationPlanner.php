<?php

namespace site7\studio\services\synchronization;

use Craft;
use craft\base\Component;
use site7\studio\models\synchronization\Conflict;
use site7\studio\models\synchronization\SynchronizationPlan;
use site7\studio\models\synchronization\SynchronizationStep;
use site7\studio\services\scanning\AssetVolumeScanner;
use site7\studio\services\scanning\CategoryGroupScanner;
use site7\studio\services\scanning\SectionScanner;
use site7\studio\services\scanning\TagGroupScanner;

/**
 * Compares a previously-installed Blueprint against a newer one (Website
 * Starter Kit System Phase 8) and classifies every difference into what's
 * safe to auto-apply, what's opt-in, and what a human has to decide -
 * never mutates anything itself. The $steps this produces are deliberately
 * shaped like Phase 6's InstallationStep (same $type vocabulary) so they
 * never need to be "executed" by anything bespoke: applying them just means
 * running the existing InstallationSession/InstallationExecutor machinery
 * again against the *new* Blueprint, which is already idempotent by design
 * (see PHASE-6-INSTALLATION-ORCHESTRATION.md's "Idempotency" finding) -
 * $steps here exist purely for preview/reporting, not for a separate
 * execution path to consume.
 *
 * Scope boundary, stated up front rather than discovered by a user: page
 * content (Blueprint `resources.pages`) is never diffed field-by-field here
 * - comparing captured content safely (without silently discarding a local
 * edit to a page) is real work this phase doesn't attempt. A change in the
 * page list is only ever surfaced as an informational note, never as an
 * auto-applied step.
 */
class SynchronizationPlanner extends Component
{
    /**
     * Deliberately untyped (rather than the concrete scanner classes) so
     * SynchronizationPlannerTest can substitute a fake object exposing only
     * findByHandle() - the same Yii-config-constructible testability
     * pattern InstallationExecutor's own $executorsByType already
     * established, since none of these scanner classes implement a shared
     * interface narrow enough to type-hint against instead.
     */
    public $categoryGroupScanner = null;
    public $tagGroupScanner = null;
    public $assetVolumeScanner = null;
    public $sectionScanner = null;

    public function init(): void
    {
        parent::init();
        $this->categoryGroupScanner ??= new CategoryGroupScanner();
        $this->tagGroupScanner ??= new TagGroupScanner();
        $this->assetVolumeScanner ??= new AssetVolumeScanner();
        $this->sectionScanner ??= new SectionScanner();
    }

    public function plan(string $handle, string $fromVersion, string $toVersion, array $oldBlueprint, array $newBlueprint): SynchronizationPlan
    {
        $steps = [];
        $removals = [];
        $conflicts = [];
        $warnings = [];
        $errors = [];

        if (empty($newBlueprint['packageHandle'])) {
            $errors[] = 'The newer Blueprint is missing its packageHandle - cannot plan a synchronization.';
            return new SynchronizationPlan($handle, $fromVersion, $toVersion, [], [], [], [], [], [], [], $warnings, $errors);
        }

        foreach ($this->resourceKinds() as $kind => [$scanner, $comparableFields]) {
            [$kindSteps, $kindRemovals, $kindConflicts] = $this->diffResourceKind(
                $kind,
                $oldBlueprint['resources'][$kind] ?? [],
                $newBlueprint['resources'][$kind] ?? [],
                $scanner,
                $comparableFields
            );
            $steps = array_merge($steps, $kindSteps);
            $removals = array_merge($removals, $kindRemovals);
            $conflicts = array_merge($conflicts, $kindConflicts);
        }

        $pluginChanges = $this->diffPlugins($oldBlueprint['requiredPlugins'] ?? [], $newBlueprint['requiredPlugins'] ?? [], $conflicts);
        $frontendChanges = $this->diffFrontend($oldBlueprint['frontendRequirements'] ?? [], $newBlueprint['frontendRequirements'] ?? []);
        $dependencyChanges = $this->diffDependencies($oldBlueprint, $newBlueprint);
        $projectConfigChanges = $this->projectConfigNotes($warnings);

        return new SynchronizationPlan(
            $handle,
            $fromVersion,
            $toVersion,
            $steps,
            $removals,
            $conflicts,
            $pluginChanges,
            $frontendChanges,
            $dependencyChanges,
            $projectConfigChanges,
            $warnings,
            $errors
        );
    }

    /** @return array<string, array{0: object, 1: string[]}> kind => [scanner, comparableFieldNames] */
    private function resourceKinds(): array
    {
        return [
            'categoryGroups' => [$this->categoryGroupScanner, ['name', 'maxLevels', 'defaultPlacement']],
            'tagGroups' => [$this->tagGroupScanner, ['name']],
            'assetVolumes' => [$this->assetVolumeScanner, ['name', 'fsHandle', 'transformFsHandle', 'transformSubpath', 'titleTranslationMethod']],
            'craftSections' => [$this->sectionScanner, ['name', 'type', 'propagationMethod', 'enableVersioning', 'maxLevels', 'defaultPlacement']],
        ];
    }

    /**
     * @return array{0: SynchronizationStep[], 1: SynchronizationStep[], 2: Conflict[]}
     */
    private function diffResourceKind(string $kind, array $oldItems, array $newItems, object $scanner, array $comparableFields): array
    {
        $steps = [];
        $removals = [];
        $conflicts = [];

        $oldByHandle = $this->keyByHandle($oldItems);
        $newByHandle = $this->keyByHandle($newItems);

        foreach ($newByHandle as $handle => $newDef) {
            $key = "{$kind}:{$handle}";

            if (!isset($oldByHandle[$handle])) {
                $steps[] = new SynchronizationStep('craft-resource', SynchronizationStep::ACTION_CREATE, $key, "Create {$kind} \"{$newDef['name']}\"", ['kind' => $this->singularKind($kind), 'data' => $newDef]);
                continue;
            }

            $oldDef = $oldByHandle[$handle];
            if ($this->fieldsEqual($oldDef, $newDef, $comparableFields)) {
                continue; // unchanged between the two Blueprint versions - nothing to do
            }

            $liveDef = $this->liveFields($scanner, $handle, $comparableFields);
            if ($liveDef === null) {
                $conflicts[] = new Conflict(Conflict::TYPE_DELETED, $key, "The new Blueprint wants to update \"{$handle}\", but it no longer exists on this site.", $oldDef, $newDef);
            } elseif (!$this->fieldsEqual($oldDef, $liveDef, $comparableFields)) {
                $conflicts[] = new Conflict(Conflict::TYPE_LOCALLY_MODIFIED, $key, "\"{$handle}\" was changed on this site since it was installed - the new Blueprint's own change was not applied automatically.", $liveDef, $newDef);
            } else {
                $steps[] = new SynchronizationStep('craft-resource', SynchronizationStep::ACTION_UPDATE, $key, "Update {$kind} \"{$newDef['name']}\"", ['kind' => $this->singularKind($kind), 'data' => $newDef]);
            }
        }

        foreach ($oldByHandle as $handle => $oldDef) {
            if (isset($newByHandle[$handle])) {
                continue;
            }

            $key = "{$kind}:{$handle}";
            $liveDef = $this->liveFields($scanner, $handle, $comparableFields);
            if ($liveDef === null) {
                continue; // already gone - nothing to remove
            }

            if (!$this->fieldsEqual($oldDef, $liveDef, $comparableFields)) {
                $conflicts[] = new Conflict(Conflict::TYPE_LOCALLY_MODIFIED, $key, "\"{$handle}\" was changed on this site since it was installed, and the new Blueprint no longer includes it - resolve manually rather than risk removing a local change.", $liveDef, []);
            } else {
                $removals[] = new SynchronizationStep('craft-resource', SynchronizationStep::ACTION_REMOVE, $key, "Remove {$kind} \"{$oldDef['name']}\"", ['kind' => $this->singularKind($kind), 'data' => $oldDef]);
            }
        }

        return [$steps, $removals, $conflicts];
    }

    private function singularKind(string $kind): string
    {
        return match ($kind) {
            'categoryGroups' => 'categoryGroup',
            'tagGroups' => 'tagGroup',
            'assetVolumes' => 'assetVolume',
            'craftSections' => 'craftSection',
            default => $kind,
        };
    }

    /** @return array<string, array> handle => item */
    private function keyByHandle(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!empty($item['handle'])) {
                $result[$item['handle']] = $item;
            }
        }
        return $result;
    }

    private function fieldsEqual(array $a, array $b, array $fields): bool
    {
        foreach ($fields as $field) {
            if (($a[$field] ?? null) !== ($b[$field] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** @return array|null the live resource's comparable fields, or null if it doesn't exist */
    private function liveFields(object $scanner, string $handle, array $fields): ?array
    {
        $live = $scanner->findByHandle($handle);
        if ($live === null) {
            return null;
        }

        $result = [];
        foreach ($fields as $field) {
            $result[$field] = match (true) {
                $field === 'propagationMethod' && isset($live->propagationMethod) => $live->propagationMethod instanceof \BackedEnum ? $live->propagationMethod->value : $live->propagationMethod,
                default => $live->$field ?? null,
            };
        }
        return $result;
    }

    /** @return array{added: array, updated: array, removed: array} */
    private function diffPlugins(array $oldPlugins, array $newPlugins, array &$conflicts): array
    {
        $oldByHandle = $this->keyByHandle($oldPlugins);
        $newByHandle = $this->keyByHandle($newPlugins);

        $added = [];
        $updated = [];
        $removed = [];

        foreach ($newByHandle as $handle => $newPlugin) {
            if (!isset($oldByHandle[$handle])) {
                $added[] = $newPlugin;
            } elseif (($oldByHandle[$handle]['versionConstraint'] ?? null) !== ($newPlugin['versionConstraint'] ?? null)) {
                // Already required at some constraint - Phase 6's InstallationPlanner only
                // ever adds a composer step for a package not yet required at all, it has
                // no "bump an existing requirement's constraint" primitive. Surfacing this
                // as a dependency conflict rather than silently doing nothing.
                $conflicts[] = new Conflict(
                    Conflict::TYPE_DEPENDENCY,
                    "plugin:{$handle}",
                    "The new Blueprint requires \"{$handle}\" at a different version constraint ({$oldByHandle[$handle]['versionConstraint']} -> {$newPlugin['versionConstraint']}) - review and update composer.json manually; automatic re-installation only handles plugins not yet required at all.",
                    $oldByHandle[$handle],
                    $newPlugin
                );
                $updated[] = ['handle' => $handle, 'from' => $oldByHandle[$handle]['versionConstraint'] ?? null, 'to' => $newPlugin['versionConstraint'] ?? null];
            }
        }

        foreach ($oldByHandle as $handle => $oldPlugin) {
            if (!isset($newByHandle[$handle])) {
                $removed[] = $oldPlugin;
            }
        }

        return ['added' => $added, 'updated' => $updated, 'removed' => $removed];
    }

    /** @return array{npmAdded: array, npmRemoved: array, npmUpdated: array, configFilesChanged: bool} */
    private function diffFrontend(array $oldFrontend, array $newFrontend): array
    {
        $oldNpm = [];
        foreach (($oldFrontend['npmPackages'] ?? []) as $pkg) {
            $oldNpm[$pkg['name']] = $pkg;
        }
        $newNpm = [];
        foreach (($newFrontend['npmPackages'] ?? []) as $pkg) {
            $newNpm[$pkg['name']] = $pkg;
        }

        $added = [];
        $updated = [];
        foreach ($newNpm as $name => $pkg) {
            if (!isset($oldNpm[$name])) {
                $added[] = $pkg;
            } elseif (($oldNpm[$name]['version'] ?? null) !== ($pkg['version'] ?? null)) {
                $updated[] = ['name' => $name, 'from' => $oldNpm[$name]['version'] ?? null, 'to' => $pkg['version'] ?? null];
            }
        }
        $removed = array_values(array_filter($oldNpm, fn($name) => !isset($newNpm[$name]), ARRAY_FILTER_USE_KEY));

        $oldConfigFiles = $oldFrontend['frontendTooling']['configFiles'] ?? [];
        $newConfigFiles = $newFrontend['frontendTooling']['configFiles'] ?? [];
        sort($oldConfigFiles);
        sort($newConfigFiles);

        return [
            'npmAdded' => $added,
            'npmRemoved' => $removed,
            'npmUpdated' => $updated,
            'configFilesChanged' => $oldConfigFiles !== $newConfigFiles,
        ];
    }

    /** @return string[] informational notes only */
    private function diffDependencies(array $oldBlueprint, array $newBlueprint): array
    {
        $notes = [];

        if (($oldBlueprint['installationOrder'] ?? []) !== ($newBlueprint['installationOrder'] ?? [])) {
            $notes[] = 'The installation order changed between versions - re-applying the new Blueprint will use the new order.';
        }

        $oldPages = array_column($oldBlueprint['resources']['pages'] ?? [], 'slug');
        $newPages = array_column($newBlueprint['resources']['pages'] ?? [], 'slug');
        sort($oldPages);
        sort($newPages);
        if ($oldPages !== $newPages) {
            $notes[] = 'The captured page list changed between versions - page/content differences are not auto-diffed by this planner and require manual review.';
        }

        return $notes;
    }

    /** @return string[] informational notes only */
    private function projectConfigNotes(array &$warnings): array
    {
        $notes = ['Project Config will be rebuilt once any of the above changes are applied.'];

        if (Craft::$app->getProjectConfig()->areChangesPending()) {
            $warnings[] = 'Project Config already has pending changes on this site, independent of this synchronization - review those before proceeding.';
        }

        return $notes;
    }
}
