<?php

namespace site7\studio\services\installation;

use craft\base\Component;
use site7\studio\interfaces\StepExecutorInterface;
use site7\studio\models\installation\InstallationPlan;
use site7\studio\models\installation\InstallationReport;
use site7\studio\models\installation\InstallationStep;
use site7\studio\models\installation\ValidationResult;
use site7\studio\services\installation\executors\ComposerExecutor;
use site7\studio\services\installation\executors\ContentInstallExecutor;
use site7\studio\services\installation\executors\CraftResourceInstallExecutor;
use site7\studio\services\installation\executors\FrontendInstallExecutor;
use site7\studio\services\installation\executors\NpmExecutor;
use site7\studio\services\installation\executors\PluginInstallExecutor;
use site7\studio\services\installation\executors\ProjectConfigExecutor;

/**
 * Executes an already-built InstallationPlan (Website Starter Kit System
 * Phase 6) - never a Blueprint directly, and never without a passing
 * ValidationResult in hand first. Contains no installation logic of its
 * own: every step is delegated to the StepExecutorInterface registered for
 * its type, one dedicated class per concern (Composer, npm, Craft plugin
 * install, Craft resource create/update, captured content install,
 * frontend file copy, Project Config rebuild).
 *
 * Designed to be safe to call from any future execution context (a CLI
 * command, a Craft queue job, the Marketplace, a Cloud runner) - this
 * class has no dependency on the web request lifecycle, and $onProgress is
 * a plain callable rather than an event tied to any particular transport.
 */
class InstallationExecutor extends Component
{
    /**
     * Step types whose failure stops the whole run - everything after a
     * failed Composer/plugin-install step would be operating on a target
     * whose actual state no longer matches what the plan assumed (a Craft
     * resource step might reference an Entry Type that plugin was supposed
     * to provide, for instance). Craft resource/content/frontend/Project
     * Config step failures are reported but don't stop later steps, since
     * they're independent of each other.
     */
    private const CRITICAL_TYPES = [
        InstallationStep::TYPE_COMPOSER,
        InstallationStep::TYPE_PLUGIN_INSTALL,
    ];

    /**
     * @var array<string, StepExecutorInterface> Public and
     * Yii-config-constructible (`new InstallationExecutor(['executorsByType'
     * => [...fakes]])`) specifically so InstallationExecutorTest can
     * substitute fake StepExecutorInterface implementations and test the
     * orchestration logic (grouping, critical-type stop behavior, report
     * shape) without any real executor or live Craft app. Any type not
     * supplied gets its real default in init().
     */
    public array $executorsByType = [];

    public function init(): void
    {
        parent::init();
        $this->executorsByType += [
            InstallationStep::TYPE_COMPOSER => new ComposerExecutor(),
            InstallationStep::TYPE_PLUGIN_INSTALL => new PluginInstallExecutor(),
            InstallationStep::TYPE_CRAFT_RESOURCE => new CraftResourceInstallExecutor(),
            InstallationStep::TYPE_CONTENT => new ContentInstallExecutor(),
            InstallationStep::TYPE_FRONTEND => new FrontendInstallExecutor(),
            InstallationStep::TYPE_NPM => new NpmExecutor(),
            InstallationStep::TYPE_PROJECT_CONFIG => new ProjectConfigExecutor(),
        ];
    }

    /**
     * @param callable(InstallationStep, string, ?string): void|null $onProgress invoked once per step as (step, status, message)
     */
    public function execute(InstallationPlan $plan, ValidationResult $validationResult, bool $dryRun = false, ?callable $onProgress = null): InstallationReport
    {
        if (!$validationResult->valid) {
            return new InstallationReport(
                false,
                [],
                [],
                [],
                $validationResult->warnings,
                array_merge(['Validation failed - no steps were executed.'], $validationResult->errors),
                $validationResult
            );
        }

        $completed = [];
        $skipped = [];
        $failed = [];
        $errors = [];
        $stopped = false;

        $stepsByType = [];
        foreach ($plan->steps as $step) {
            $stepsByType[$step->type][] = $step;
        }

        foreach ($stepsByType as $type => $stepsOfType) {
            if ($stopped) {
                foreach ($stepsOfType as $step) {
                    $message = 'Skipped - a prior critical step failed.';
                    $skipped[] = ['step' => $step->toArray(), 'message' => $message];
                    if ($onProgress) {
                        $onProgress($step, 'skipped', $message);
                    }
                }
                continue;
            }

            $executor = $this->executorsByType[$type] ?? null;
            if (!$executor) {
                foreach ($stepsOfType as $step) {
                    $skipped[] = ['step' => $step->toArray(), 'message' => "No executor registered for step type '{$type}'."];
                }
                continue;
            }

            foreach ($executor->execute($stepsOfType, $dryRun) as $result) {
                if ($onProgress) {
                    $onProgress($result['step'], $result['status'], $result['message']);
                }

                $record = ['step' => $result['step']->toArray(), 'message' => $result['message']];
                match ($result['status']) {
                    'completed' => $completed[] = $record,
                    'skipped' => $skipped[] = $record,
                    'failed' => $failed[] = $record,
                    default => null,
                };

                if ($result['status'] === 'failed') {
                    $errors[] = "{$result['step']->label}: {$result['message']}";
                    if (in_array($type, self::CRITICAL_TYPES, true)) {
                        $stopped = true;
                    }
                }
            }
        }

        return new InstallationReport(
            !$stopped && empty($failed),
            $completed,
            $skipped,
            $failed,
            $validationResult->warnings,
            $errors,
            $validationResult
        );
    }
}
