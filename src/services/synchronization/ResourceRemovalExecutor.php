<?php

namespace site7\studio\services\synchronization;

use Craft;
use site7\studio\models\synchronization\SynchronizationStep;

/**
 * Applies opt-in resource removals only (Website Starter Kit System Phase 8)
 * - the one capability neither Phase 6 nor Phase 7 has at all (both are
 * deliberately create/update-only). Scope is deliberately narrower than
 * SynchronizationPlanner's own resource-kind coverage: only Category and Tag
 * Groups are ever actually removed here via official Craft service APIs
 * (Categories::deleteGroupById()/Tags::deleteTagGroupById()) - Craft
 * Sections and Asset Volumes are never auto-removed, since Phase 6 already
 * drew the same line for *creating* them (a Section's own Entry Types/field
 * layout, and a Volume's referenced filesystem, aren't things this plugin
 * safely owns the lifecycle of). A removal step for either kind is reported
 * as skipped with an explanation rather than silently ignored.
 *
 * Every step passed in here has already been explicitly confirmed by the
 * user (one confirmation per resource key - see SynchronizationOrchestratorService) -
 * this class performs no further "is this safe" judgment beyond the
 * kind-based scope boundary above.
 */
class ResourceRemovalExecutor
{
    /**
     * @param SynchronizationStep[] $steps
     * @return array<int, array{step: SynchronizationStep, status: 'completed'|'skipped'|'failed', message: ?string}>
     */
    public function execute(array $steps, bool $dryRun): array
    {
        $results = [];

        foreach ($steps as $step) {
            $kind = $step->payload['kind'] ?? null;
            $handle = $step->payload['data']['handle'] ?? null;

            if ($dryRun) {
                $results[] = ['step' => $step, 'status' => 'skipped', 'message' => "Dry run - would remove {$kind} '{$handle}'."];
                continue;
            }

            try {
                $message = match ($kind) {
                    'categoryGroup' => $this->removeCategoryGroup($handle),
                    'tagGroup' => $this->removeTagGroup($handle),
                    default => "Removing a '{$kind}' is not supported - only Category and Tag Groups can be auto-removed. Remove it manually if intended.",
                };
                $status = str_starts_with($message, 'Removing a') ? 'skipped' : 'completed';
                $results[] = ['step' => $step, 'status' => $status, 'message' => $message];
            } catch (\Throwable $e) {
                $results[] = ['step' => $step, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function removeCategoryGroup(string $handle): string
    {
        $group = Craft::$app->getCategories()->getGroupByHandle($handle);
        if (!$group) {
            return "Category Group '{$handle}' no longer exists - nothing to remove.";
        }
        if (!Craft::$app->getCategories()->deleteGroupById($group->id)) {
            throw new \Exception("Could not delete Category Group '{$handle}'.");
        }
        return "Removed Category Group '{$handle}'.";
    }

    private function removeTagGroup(string $handle): string
    {
        $group = Craft::$app->getTags()->getTagGroupByHandle($handle);
        if (!$group) {
            return "Tag Group '{$handle}' no longer exists - nothing to remove.";
        }
        if (!Craft::$app->getTags()->deleteTagGroupById($group->id)) {
            throw new \Exception("Could not delete Tag Group '{$handle}'.");
        }
        return "Removed Tag Group '{$handle}'.";
    }
}
