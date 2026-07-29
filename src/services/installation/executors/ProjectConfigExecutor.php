<?php

namespace site7\studio\services\installation\executors;

use Craft;
use site7\studio\interfaces\StepExecutorInterface;

/**
 * Rebuilds Project Config after every element-level save above (Craft
 * resources, content) - matching the same call
 * PackageManagerService::invalidateCraftCaches() already makes elsewhere in
 * this plugin, so the CP's "Apply pending project config YAML changes"
 * banner never appears after an install. This never touches
 * config/project/*.yaml directly - every prior step already went through
 * official Craft service/element APIs, which write to Project Config
 * themselves; this only asks Craft to reconcile its own YAML with what's
 * now in the database.
 */
class ProjectConfigExecutor implements StepExecutorInterface
{
    public function execute(array $steps, bool $dryRun): array
    {
        $results = [];

        foreach ($steps as $step) {
            if ($dryRun) {
                $results[] = ['step' => $step, 'status' => 'skipped', 'message' => 'Dry run - would rebuild Project Config.'];
                continue;
            }

            try {
                Craft::$app->getProjectConfig()->rebuild();
                $results[] = ['step' => $step, 'status' => 'completed', 'message' => null];
            } catch (\Throwable $e) {
                $results[] = ['step' => $step, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }
}
