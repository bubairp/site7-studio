<?php

namespace site7\studio\services\installation\executors;

use Craft;
use site7\studio\interfaces\StepExecutorInterface;
use site7\studio\models\installation\InstallationStep;

/**
 * Requires Composer packages via Craft's own official Composer service
 * (Craft::$app->getComposer()->install()) - the exact API Craft's own
 * Plugin Store uses. That service already backs up composer.json/
 * composer.lock and restores them on failure, so this executor doesn't
 * need to implement its own rollback.
 *
 * Batches every step into a single `composer update <packages>
 * --with-dependencies` call rather than one process per package - both
 * faster and matches how Craft's own service is designed to be called.
 */
class ComposerExecutor implements StepExecutorInterface
{
    public function execute(array $steps, bool $dryRun): array
    {
        if (empty($steps)) {
            return [];
        }

        if ($dryRun) {
            return array_map(
                fn(InstallationStep $step) => [
                    'step' => $step,
                    'status' => 'skipped',
                    'message' => "Dry run - would require {$step->payload['package']} {$step->payload['versionConstraint']}.",
                ],
                $steps
            );
        }

        $requirements = [];
        foreach ($steps as $step) {
            $requirements[$step->payload['package']] = $step->payload['versionConstraint'];
        }

        try {
            Craft::$app->getComposer()->install($requirements);
        } catch (\Throwable $e) {
            return array_map(
                fn(InstallationStep $step) => ['step' => $step, 'status' => 'failed', 'message' => $e->getMessage()],
                $steps
            );
        }

        return array_map(
            fn(InstallationStep $step) => ['step' => $step, 'status' => 'completed', 'message' => null],
            $steps
        );
    }
}
