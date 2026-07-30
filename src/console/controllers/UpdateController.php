<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\Site7Studio;
use site7\studio\models\installation\InstallationSession;
use site7\studio\models\installation\InstallationStep;
use site7\studio\models\synchronization\SynchronizationSession;
use site7\studio\models\synchronization\SynchronizationStep;
use site7\studio\services\installation\executors\ProjectConfigExecutor;
use site7\studio\services\synchronization\ResourceRemovalExecutor;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * CLI entry point for the Synchronization & Update Engine (Website Starter
 * Kit System Phase 8) - a thin presentation layer over
 * SynchronizationPlanner/SynchronizationValidator/SynchronizationOrchestratorService,
 * the same way InstallController is for Phase 7. No diff or execution logic
 * lives here.
 */
class UpdateController extends Controller
{
    public bool $dryRun = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'run') {
            $options[] = 'dryRun';
        }
        return $options;
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['d' => 'dryRun']);
    }

    /** Lists installed Starter Kits with a newer version available (Update Wizard Step 1). */
    public function actionList(): int
    {
        $updates = Site7Studio::getInstance()->updateCatalog->listAvailableUpdates();
        if (empty($updates)) {
            $this->stdout("No updates available.\n");
            return ExitCode::OK;
        }

        foreach ($updates as $update) {
            $this->stdout("{$update['handle']}: {$update['installedVersion']} -> {$update['availableVersion']} ({$update['name']})\n");
        }

        return ExitCode::OK;
    }

    /** Builds and prints a synchronization plan without creating a session or applying anything (Update Wizard Steps 2-3). */
    public function actionPlan(string $handle): int
    {
        $plugin = Site7Studio::getInstance();
        $installed = $plugin->installedStarterKits->getInstalled($handle);
        if ($installed === null) {
            $this->stderr("'{$handle}' is not tracked as installed - nothing to synchronize.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $newBlueprint = $plugin->starterKitCatalog->getBlueprint($handle);
        if ($newBlueprint === null) {
            $this->stderr("No blueprint.json found for '{$handle}' in packages/.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $toVersion = $plugin->starterKitCatalog->getManifestVersion($handle) ?? $installed['installedVersion'];
        $plan = $plugin->synchronizationPlanner->plan($handle, $installed['installedVersion'], $toVersion, $installed['blueprint'], $newBlueprint);
        $validation = $plugin->synchronizationValidator->validateSynchronization($plan, null);

        $this->printPlan($plan->toArray(), $validation->toArray());

        return $validation->valid ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Creates a session, plans+validates it, applies it (create/update via a
     * reused InstallationSession, opt-in removals via ResourceRemovalExecutor),
     * and prints the final report. `--removals=key1,key2` opts specific
     * dropped resources into removal; omit to leave all of them untouched.
     */
    public function actionRun(string $handle, string $removals = ''): int
    {
        $plugin = Site7Studio::getInstance();
        $installed = $plugin->installedStarterKits->getInstalled($handle);
        if ($installed === null) {
            $this->stderr("'{$handle}' is not tracked as installed - nothing to synchronize.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $newBlueprint = $plugin->starterKitCatalog->getBlueprint($handle);
        if ($newBlueprint === null) {
            $this->stderr("No blueprint.json found for '{$handle}' in packages/.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $toVersion = $plugin->starterKitCatalog->getManifestVersion($handle) ?? $installed['installedVersion'];
        $plan = $plugin->synchronizationPlanner->plan($handle, $installed['installedVersion'], $toVersion, $installed['blueprint'], $newBlueprint);
        $validation = $plugin->synchronizationValidator->validateSynchronization($plan, null);
        $this->printPlan($plan->toArray(), $validation->toArray());

        if (!$validation->valid) {
            $this->stderr("\nValidation failed - aborting.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $session = $plugin->synchronizationSessions->create(
            $handle,
            $plugin->starterKitCatalog->packagePath($handle),
            $installed['installedVersion'],
            $toVersion,
            $installed['blueprint'],
            $newBlueprint,
            $this->dryRun
        );
        $session->plan = $plan->toArray();
        $session->validationResult = $validation->toArray();
        $session->confirmedRemovalKeys = array_values(array_filter(array_map('trim', explode(',', $removals))));
        $session->status = SynchronizationSession::STATUS_CONFIRMED;
        $plugin->synchronizationSessions->save($session);

        $this->stdout("\nApplying synchronization (session {$session->uid}, dryRun=" . ($this->dryRun ? 'true' : 'false') . ")...\n");

        $lastLogCount = 0;
        $report = $plugin->synchronizationOrchestrator->execute($session, function (InstallationSession $s) use (&$lastLogCount) {
            foreach (array_slice($s->progressLog, $lastLogCount) as $entry) {
                $color = match ($entry['status']) {
                    'completed' => Console::FG_GREEN,
                    'failed' => Console::FG_RED,
                    default => Console::FG_YELLOW,
                };
                $this->stdout("[{$entry['status']}] {$entry['label']}" . ($entry['message'] ? " - {$entry['message']}" : '') . "\n", $color);
            }
            $lastLogCount = count($s->progressLog);
        });

        $plugin->synchronizationSessions->save($session);

        foreach ($report->appliedRemovals as $removal) {
            $this->stdout("[removed] {$removal['step']['label']} - {$removal['message']}\n", Console::FG_YELLOW);
        }

        $this->stdout("\nSynchronization " . ($report->success ? 'completed' : 'failed') . ".\n");

        return $report->success ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Applies a session's confirmed opt-in removals and rebuilds Project
     * Config once more - never invoked directly by a user. This is the
     * subprocess SynchronizationOrchestratorService spawns fresh so removal
     * never runs in the same process that just called
     * InstallationOrchestratorService (see that class's docblock for why).
     */
    public function actionApplyRemovals(string $sessionUid): int
    {
        $plugin = Site7Studio::getInstance();
        $session = $plugin->synchronizationSessions->loadSession($sessionUid);
        if ($session === null) {
            $this->stderr("No SynchronizationSession found for uid {$sessionUid}.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $removalSteps = array_values(array_filter(
            $session->plan['removals'] ?? [],
            fn(array $s) => in_array($s['key'], $session->confirmedRemovalKeys, true)
        ));
        $stepObjects = array_map(
            fn(array $s) => new SynchronizationStep($s['type'], $s['action'], $s['key'], $s['label'], $s['payload']),
            $removalSteps
        );

        $applied = [];
        foreach ((new ResourceRemovalExecutor())->execute($stepObjects, false) as $result) {
            $applied[] = ['step' => $result['step']->toArray(), 'message' => $result['message']];
        }

        if (!empty($applied)) {
            (new ProjectConfigExecutor())->execute([new InstallationStep(InstallationStep::TYPE_PROJECT_CONFIG, 'sync-rebuild', 'Rebuild Project Config after removals')], false);
        }

        $session->appliedRemovals = $applied;
        $plugin->synchronizationSessions->save($session);

        return ExitCode::OK;
    }

    private function printPlan(array $plan, array $validation): void
    {
        $this->stdout("\nPlan: {$plan['fromVersion']} -> {$plan['toVersion']}\n");
        $this->stdout(count($plan['steps']) . " step(s) safe to apply automatically.\n");
        foreach ($plan['steps'] as $step) {
            $this->stdout("  [{$step['action']}] {$step['label']}\n");
        }

        if (!empty($plan['removals'])) {
            $this->stdout(count($plan['removals']) . " resource(s) proposed for removal (opt-in, use --removals=):\n", Console::FG_YELLOW);
            foreach ($plan['removals'] as $removal) {
                $this->stdout("  [{$removal['key']}] {$removal['label']}\n", Console::FG_YELLOW);
            }
        }

        if (!empty($plan['conflicts'])) {
            $this->stdout(count($plan['conflicts']) . " conflict(s) require manual review:\n", Console::FG_RED);
            foreach ($plan['conflicts'] as $conflict) {
                $this->stdout("  [{$conflict['type']}] {$conflict['resourceKey']}: {$conflict['description']}\n", Console::FG_RED);
            }
        }

        foreach ($validation['checks'] as $check) {
            $mark = $check['passed'] ? Console::ansiFormat('OK', [Console::FG_GREEN]) : Console::ansiFormat('FAIL', [Console::FG_RED]);
            $this->stdout("[{$mark}] {$check['name']}: {$check['detail']}\n");
        }
        foreach ($validation['warnings'] as $warning) {
            $this->stdout("Warning: {$warning}\n", Console::FG_YELLOW);
        }
        foreach ($validation['errors'] as $error) {
            $this->stderr("Error: {$error}\n", Console::FG_RED);
        }
    }
}
