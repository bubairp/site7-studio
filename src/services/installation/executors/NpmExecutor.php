<?php

namespace site7\studio\services\installation\executors;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use site7\studio\interfaces\StepExecutorInterface;
use site7\studio\services\FrontendToolingScanner;

/**
 * Runs `npm install` in the target project's own frontend root - Craft has
 * no equivalent official service for npm (unlike Composer), so this uses
 * Symfony Process directly, the same library Craft's own Composer service
 * uses internally. Deliberately does not run a build script - only
 * dependency installation; the phase's own scope excludes executing build
 * tools.
 */
class NpmExecutor implements StepExecutorInterface
{
    private const TIMEOUT_SECONDS = 600;

    public ?FrontendToolingScanner $frontendScanner = null;

    public function __construct()
    {
        $this->frontendScanner = new FrontendToolingScanner();
    }

    public function execute(array $steps, bool $dryRun): array
    {
        if (empty($steps)) {
            return [];
        }

        $npmPath = (new ExecutableFinder())->find('npm');
        $targetRoot = $this->frontendScanner->detect()['root'] ?? null;

        if ($dryRun || !$npmPath || !$targetRoot) {
            $reason = match (true) {
                $dryRun => 'Dry run - would run `npm install`.',
                !$npmPath => 'npm executable not found in PATH - skipped.',
                default => 'No frontend root detected on the target - skipped.',
            };
            return array_map(fn($step) => ['step' => $step, 'status' => 'skipped', 'message' => $reason], $steps);
        }

        $process = new Process([$npmPath, 'install'], $targetRoot);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->mustRun();
        } catch (\Throwable $e) {
            return array_map(fn($step) => ['step' => $step, 'status' => 'failed', 'message' => $e->getMessage()], $steps);
        }

        return array_map(fn($step) => ['step' => $step, 'status' => 'completed', 'message' => "Ran `npm install` in {$targetRoot}."], $steps);
    }
}
