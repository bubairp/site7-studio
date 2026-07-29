<?php

namespace site7\studio\services\installation\executors;

use Craft;
use site7\studio\interfaces\StepExecutorInterface;

/**
 * Installs/enables a Craft plugin via the official Plugins service -
 * idempotent: a plugin already installed and enabled is skipped, not
 * re-installed.
 */
class PluginInstallExecutor implements StepExecutorInterface
{
    public function execute(array $steps, bool $dryRun): array
    {
        $results = [];
        $pluginsService = Craft::$app->getPlugins();

        foreach ($steps as $step) {
            $handle = $step->payload['handle'];

            if ($pluginsService->isPluginInstalled($handle) && $pluginsService->isPluginEnabled($handle)) {
                $results[] = ['step' => $step, 'status' => 'skipped', 'message' => 'Already installed and enabled.'];
                continue;
            }

            if ($dryRun) {
                $results[] = ['step' => $step, 'status' => 'skipped', 'message' => 'Dry run - would install/enable.'];
                continue;
            }

            try {
                if (!$pluginsService->isPluginInstalled($handle) && !$pluginsService->installPlugin($handle)) {
                    $results[] = ['step' => $step, 'status' => 'failed', 'message' => "Could not install plugin '{$handle}'."];
                    continue;
                }
                if (!$pluginsService->isPluginEnabled($handle) && !$pluginsService->enablePlugin($handle)) {
                    $results[] = ['step' => $step, 'status' => 'failed', 'message' => "Could not enable plugin '{$handle}'."];
                    continue;
                }
                $results[] = ['step' => $step, 'status' => 'completed', 'message' => null];
            } catch (\Throwable $e) {
                $results[] = ['step' => $step, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }
}
