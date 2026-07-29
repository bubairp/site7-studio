<?php

namespace site7\studio\services\installation\executors;

use craft\helpers\FileHelper;
use site7\studio\interfaces\StepExecutorInterface;
use site7\studio\services\FrontendToolingScanner;

/**
 * Copies the Starter Kit package's own captured frontend config files
 * (already inside the package's `frontend/` directory since Phase 4) into
 * place on the target project. Does not run npm or a build - see
 * NpmExecutor for the former; running a build script is out of this
 * phase's scope entirely.
 */
class FrontendInstallExecutor implements StepExecutorInterface
{
    public ?FrontendToolingScanner $frontendScanner = null;

    public function __construct()
    {
        $this->frontendScanner = new FrontendToolingScanner();
    }

    public function execute(array $steps, bool $dryRun): array
    {
        $results = [];

        foreach ($steps as $step) {
            $sourceDir = $step->payload['sourceDir'];
            $configFiles = $step->payload['configFiles'];
            $targetRoot = $this->resolveTargetRoot();

            if ($dryRun) {
                $results[] = ['step' => $step, 'status' => 'skipped', 'message' => 'Dry run - would copy ' . count($configFiles) . " file(s) to {$targetRoot}."];
                continue;
            }

            try {
                FileHelper::createDirectory($targetRoot);
                $copied = 0;
                foreach ($configFiles as $filename) {
                    $source = $sourceDir . '/' . $filename;
                    if (is_file($source)) {
                        copy($source, $targetRoot . '/' . $filename);
                        $copied++;
                    }
                }
                $results[] = ['step' => $step, 'status' => 'completed', 'message' => "Copied {$copied} file(s) to {$targetRoot}."];
            } catch (\Throwable $e) {
                $results[] = ['step' => $step, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * If the target already has a frontend project (detected the same way
     * FrontendToolingScanner does for capture), copy there; otherwise fall
     * back to a conventional `frontend/` directory at the project root.
     */
    private function resolveTargetRoot(): string
    {
        $detected = $this->frontendScanner->detect();
        return $detected['root'] ?? ($this->frontendScanner->projectRoot() . '/frontend');
    }
}
