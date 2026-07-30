<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\Site7Studio;
use site7\studio\models\installation\InstallationSession;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * CLI entry point for the Fresh-Install Setup Wizard's own orchestration
 * (Website Starter Kit System Phase 7) - a thin presentation layer over the
 * exact same InstallationStageRunner/InstallationOrchestratorService the CP
 * wizard and its queue job use. No installation logic lives here.
 */
class InstallController extends Controller
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

    /** Lists Starter Kit packages available to install (Wizard Step 1). */
    public function actionList(): int
    {
        $kits = Site7Studio::getInstance()->starterKitCatalog->listAvailable();
        if (empty($kits)) {
            $this->stdout("No Starter Kit packages found in the packages/ directory.\n");
            return ExitCode::OK;
        }

        foreach ($kits as $kit) {
            $status = $kit['installable'] ? Console::ansiFormat('installable', [Console::FG_GREEN]) : Console::ansiFormat('not installable', [Console::FG_RED]);
            $this->stdout("{$kit['handle']} ({$kit['version']}) - {$kit['name']} [{$status}]\n");
            foreach ($kit['notes'] as $note) {
                $this->stdout("  - {$note}\n", Console::FG_YELLOW);
            }
        }

        return ExitCode::OK;
    }

    /** Runs read-only validation + planning for a Starter Kit, without creating a session or installing anything (Wizard Steps 2-3). */
    public function actionValidate(string $handle): int
    {
        $catalog = Site7Studio::getInstance()->starterKitCatalog;
        $blueprint = $catalog->getBlueprint($handle);
        if ($blueprint === null) {
            $this->stderr("No blueprint.json found for '{$handle}'.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $plan = Site7Studio::getInstance()->installationPlanner->plan($blueprint, $catalog->packagePath($handle));
        $result = Site7Studio::getInstance()->installationValidator->validateInstallation($blueprint, $plan);

        foreach ($result->checks as $check) {
            $mark = $check['passed'] ? Console::ansiFormat('OK', [Console::FG_GREEN]) : Console::ansiFormat('FAIL', [Console::FG_RED]);
            $this->stdout("[{$mark}] {$check['name']}: {$check['detail']}\n");
        }
        foreach ($result->warnings as $warning) {
            $this->stdout("Warning: {$warning}\n", Console::FG_YELLOW);
        }
        foreach ($result->errors as $error) {
            $this->stderr("Error: {$error}\n", Console::FG_RED);
        }

        $this->stdout("\n" . count($plan->steps) . " step(s) planned. Valid: " . ($result->valid ? 'yes' : 'no') . "\n");

        return $result->valid ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /** Creates a session for a Starter Kit and runs it to completion, one fresh subprocess per stage (Wizard Steps 4-5). */
    public function actionRun(string $handle): int
    {
        $catalog = Site7Studio::getInstance()->starterKitCatalog;
        $blueprint = $catalog->getBlueprint($handle);
        if ($blueprint === null) {
            $this->stderr("No blueprint.json found for '{$handle}'.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $session = Site7Studio::getInstance()->installationSessions->create($handle, $catalog->packagePath($handle), $blueprint, $this->dryRun);
        $this->stdout("Installing '{$handle}' (session {$session->uid}, dryRun=" . ($this->dryRun ? 'true' : 'false') . ")\n");

        $lastLogCount = 0;
        $final = Site7Studio::getInstance()->installationOrchestrator->runToCompletion(
            $session->uid,
            function (InstallationSession $s) use (&$lastLogCount) {
                foreach (array_slice($s->progressLog, $lastLogCount) as $entry) {
                    $color = match ($entry['status']) {
                        'completed' => Console::FG_GREEN,
                        'failed' => Console::FG_RED,
                        default => Console::FG_YELLOW,
                    };
                    $this->stdout("[{$entry['status']}] {$entry['label']}" . ($entry['message'] ? " - {$entry['message']}" : '') . "\n", $color);
                }
                $lastLogCount = count($s->progressLog);
            }
        );

        // Website Starter Kit System Phase 8 baseline: recorded whenever the
        // session actually ran (completed OR failed on a real step), not
        // only on a fully clean completion - a partial install (e.g. one
        // non-critical step failed) still needs a baseline for a later
        // SynchronizationPlanner run to diff against. Only skipped when
        // nothing was ever attempted at all (fatalError - missing blueprint
        // or a failed pre-flight validation).
        if ($final->fatalError === null) {
            $version = $catalog->getManifestVersion($handle) ?? '0.0.0';
            Site7Studio::getInstance()->installedStarterKits->recordInstall($handle, $version, $final->blueprint ?? $blueprint);
        }

        if ($final->fatalError) {
            $this->stderr("Fatal: {$final->fatalError}\n", Console::FG_RED);
        }

        $this->stdout("\nInstallation " . $final->status . ".\n");

        return $final->status === InstallationSession::STATUS_COMPLETED ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Runs exactly one stage of an existing session and exits - never
     * invoked directly by a user. This is the subprocess
     * InstallationOrchestratorService spawns fresh for every stage, which is
     * what gives the composer/plugin-install process boundary its real
     * guarantee (see that class's docblock).
     */
    public function actionRunStage(string $sessionUid): int
    {
        $session = Site7Studio::getInstance()->installationSessions->loadSession($sessionUid);
        if ($session === null) {
            $this->stderr("No InstallationSession found for uid {$sessionUid}.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $result = Site7Studio::getInstance()->installationStageRunner->runNextStage($session);

        return $result['fatal'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
