<?php

namespace site7\studio\services\installation\executors;

use Craft;
use site7\studio\interfaces\StepExecutorInterface;

/**
 * Writes Project Config out after every element-level save above (Craft
 * resources, content), so the CP's "Apply pending project config YAML
 * changes" banner never appears after an install. Every prior step already
 * went through official Craft service/element APIs, which update Project
 * Config themselves; this only saves the buffered changes and writes the
 * YAML files from that config.
 *
 * It used to call ProjectConfig::rebuild(), which regenerates the whole
 * project config from the database and drops anything that only lives in
 * project config - it stripped the add-menu groups from every block type of
 * the page-builder field (docs/43 #21, the same reason
 * PackageManagerService::invalidateCraftCaches() stopped rebuilding).
 */
class ProjectConfigExecutor implements StepExecutorInterface
{
    public function execute(array $steps, bool $dryRun): array
    {
        $results = [];

        foreach ($steps as $step) {
            if ($dryRun) {
                $results[] = ['step' => $step, 'status' => 'skipped', 'message' => 'Dry run - would write Project Config.'];
                continue;
            }

            try {
                $projectConfig = Craft::$app->getProjectConfig();
                $projectConfig->saveModifiedConfigData();
                $projectConfig->writeYamlFiles(true);
                $results[] = ['step' => $step, 'status' => 'completed', 'message' => null];
            } catch (\Throwable $e) {
                $results[] = ['step' => $step, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }
}
