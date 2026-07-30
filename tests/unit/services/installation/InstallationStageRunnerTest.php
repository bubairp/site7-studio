<?php

namespace site7\studio\tests\unit\services\installation;

use Codeception\Test\Unit;
use site7\studio\interfaces\StepExecutorInterface;
use site7\studio\models\installation\InstallationPlan;
use site7\studio\models\installation\InstallationSession;
use site7\studio\models\installation\InstallationStep;
use site7\studio\models\installation\ValidationResult;
use site7\studio\services\installation\InstallationExecutor;
use site7\studio\services\installation\InstallationPlanner;
use site7\studio\services\installation\InstallationSessionService;
use site7\studio\services\installation\InstallationStageRunner;
use site7\studio\services\installation\InstallationValidator;

/**
 * Covers InstallationStageRunner's stage-splitting/bookkeeping logic
 * (Website Starter Kit System Phase 7) - the composer/install and
 * install/project-config process boundaries, the empty-stage fast path,
 * validation short-circuiting, and critical-failure handling - using fakes
 * for every live-Craft-app dependent collaborator (InstallationPlanner's own
 * planPlugins() needs a live app the same way InstallationPlannerTest
 * already documents; that's covered by live verification instead, same as
 * Phase 6).
 */
class InstallationStageRunnerTest extends Unit
{
    protected \UnitTester $tester;

    private function fakePlanner(InstallationPlan $plan): InstallationPlanner
    {
        return new class ($plan) extends InstallationPlanner {
            public function __construct(private readonly InstallationPlan $planToReturn)
            {
            }

            public function plan(array $blueprint, string $packagePath): InstallationPlan
            {
                return $this->planToReturn;
            }
        };
    }

    private function fakeValidator(ValidationResult $result): InstallationValidator
    {
        return new class ($result) extends InstallationValidator {
            public function __construct(private readonly ValidationResult $resultToReturn)
            {
            }

            public function validateInstallation(array $blueprint, ?InstallationPlan $plan = null): ValidationResult
            {
                return $this->resultToReturn;
            }
        };
    }

    private function fakeStepExecutor(string $status = 'completed'): StepExecutorInterface
    {
        return new class ($status) implements StepExecutorInterface {
            public array $receivedSteps = [];

            public function __construct(private readonly string $status)
            {
            }

            public function execute(array $steps, bool $dryRun): array
            {
                $this->receivedSteps = $steps;
                return array_map(fn($step) => ['step' => $step, 'status' => $this->status, 'message' => null], $steps);
            }
        };
    }

    private function inMemorySessions(): InstallationSessionService
    {
        return new class extends InstallationSessionService {
            public array $store = [];

            public function save(InstallationSession $session): void
            {
                $this->store[$session->uid] = $session;
            }

            public function loadSession(string $uid): ?InstallationSession
            {
                return $this->store[$uid] ?? null;
            }
        };
    }

    private function session(): InstallationSession
    {
        return new InstallationSession('uid-1', 'demo-kit', '/tmp/demo-kit', blueprint: ['schemaVersion' => '1']);
    }

    public function testComposerInstallAndProjectConfigRunAsThreeSeparateStages()
    {
        $composerStep = new InstallationStep(InstallationStep::TYPE_COMPOSER, 'vendor/pkg', 'Require vendor/pkg');
        $pluginStep = new InstallationStep(InstallationStep::TYPE_PLUGIN_INSTALL, 'pkg', 'Install pkg');
        $projectConfigStep = new InstallationStep(InstallationStep::TYPE_PROJECT_CONFIG, 'rebuild', 'Rebuild Project Config');
        $plan = new InstallationPlan([$composerStep, $pluginStep, $projectConfigStep], ['errors' => [], 'warnings' => []]);

        $composerExecutor = $this->fakeStepExecutor();
        $pluginExecutor = $this->fakeStepExecutor();
        $projectConfigExecutor = $this->fakeStepExecutor();
        $executor = new InstallationExecutor(['executorsByType' => [
            InstallationStep::TYPE_COMPOSER => $composerExecutor,
            InstallationStep::TYPE_PLUGIN_INSTALL => $pluginExecutor,
            InstallationStep::TYPE_PROJECT_CONFIG => $projectConfigExecutor,
        ]]);

        $runner = new InstallationStageRunner([
            'planner' => $this->fakePlanner($plan),
            'validator' => $this->fakeValidator(new ValidationResult(true, [], [], [])),
            'executor' => $executor,
            'sessions' => $this->inMemorySessions(),
        ]);

        $session = $this->session();

        $first = $runner->runNextStage($session);
        $this->assertTrue($first['hasNextStage']);
        $this->assertSame(['composer'], $session->stagesCompleted);
        $this->assertCount(1, $composerExecutor->receivedSteps);
        $this->assertSame([], $pluginExecutor->receivedSteps);

        $second = $runner->runNextStage($session);
        $this->assertTrue($second['hasNextStage']);
        $this->assertSame(['composer', 'install'], $session->stagesCompleted);
        $this->assertCount(1, $pluginExecutor->receivedSteps);
        $this->assertSame([], $projectConfigExecutor->receivedSteps);

        $third = $runner->runNextStage($session);
        $this->assertFalse($third['hasNextStage']);
        $this->assertSame(['composer', 'install', 'project-config'], $session->stagesCompleted);
        $this->assertCount(1, $projectConfigExecutor->receivedSteps);
        $this->assertSame(InstallationSession::STATUS_COMPLETED, $session->status);
    }

    public function testEmptyStagesAreSkippedInTheSameCall()
    {
        $contentStep = new InstallationStep(InstallationStep::TYPE_CONTENT, 'demo-kit', 'Install content');
        $plan = new InstallationPlan([$contentStep], ['errors' => [], 'warnings' => []]);

        $contentExecutor = $this->fakeStepExecutor();
        $executor = new InstallationExecutor(['executorsByType' => [InstallationStep::TYPE_CONTENT => $contentExecutor]]);

        $runner = new InstallationStageRunner([
            'planner' => $this->fakePlanner($plan),
            'validator' => $this->fakeValidator(new ValidationResult(true, [], [], [])),
            'executor' => $executor,
            'sessions' => $this->inMemorySessions(),
        ]);

        $session = $this->session();
        $result = $runner->runNextStage($session);

        // No composer step and no project-config step in this plan - both
        // get skipped in this same call, leaving only 'install' to actually run.
        $this->assertFalse($result['hasNextStage']);
        $this->assertSame(['composer', 'install', 'project-config'], $session->stagesCompleted);
        $this->assertCount(1, $contentExecutor->receivedSteps);
        $this->assertSame(InstallationSession::STATUS_COMPLETED, $session->status);
    }

    public function testFailedValidationMarksTheSessionFailedWithoutExecutingAnything()
    {
        $step = new InstallationStep(InstallationStep::TYPE_CONTENT, 'demo-kit', 'Install content');
        $plan = new InstallationPlan([$step], ['errors' => [], 'warnings' => []]);
        $contentExecutor = $this->fakeStepExecutor();
        $executor = new InstallationExecutor(['executorsByType' => [InstallationStep::TYPE_CONTENT => $contentExecutor]]);

        $runner = new InstallationStageRunner([
            'planner' => $this->fakePlanner($plan),
            'validator' => $this->fakeValidator(new ValidationResult(false, ['blueprint is broken'], [], [])),
            'executor' => $executor,
            'sessions' => $this->inMemorySessions(),
        ]);

        $session = $this->session();
        $result = $runner->runNextStage($session);

        $this->assertFalse($result['hasNextStage']);
        $this->assertTrue($result['fatal']);
        $this->assertSame(InstallationSession::STATUS_FAILED, $session->status);
        $this->assertSame([], $contentExecutor->receivedSteps);
    }

    public function testAFailedComposerStageStopsBeforeTheInstallStageRuns()
    {
        $composerStep = new InstallationStep(InstallationStep::TYPE_COMPOSER, 'vendor/pkg', 'Require vendor/pkg');
        $contentStep = new InstallationStep(InstallationStep::TYPE_CONTENT, 'demo-kit', 'Install content');
        $plan = new InstallationPlan([$composerStep, $contentStep], ['errors' => [], 'warnings' => []]);

        $composerExecutor = $this->fakeStepExecutor('failed');
        $contentExecutor = $this->fakeStepExecutor();
        $executor = new InstallationExecutor(['executorsByType' => [
            InstallationStep::TYPE_COMPOSER => $composerExecutor,
            InstallationStep::TYPE_CONTENT => $contentExecutor,
        ]]);

        $runner = new InstallationStageRunner([
            'planner' => $this->fakePlanner($plan),
            'validator' => $this->fakeValidator(new ValidationResult(true, [], [], [])),
            'executor' => $executor,
            'sessions' => $this->inMemorySessions(),
        ]);

        $session = $this->session();
        $result = $runner->runNextStage($session);

        $this->assertFalse($result['hasNextStage']);
        $this->assertSame(['composer'], $session->stagesCompleted);
        $this->assertSame(InstallationSession::STATUS_FAILED, $session->status);
        // The 'install' stage (content step) never ran - a fresh process would never even be spawned for it.
        $this->assertSame([], $contentExecutor->receivedSteps);
    }

    public function testAFailedInstallStageStopsBeforeProjectConfigRuns()
    {
        $pluginStep = new InstallationStep(InstallationStep::TYPE_PLUGIN_INSTALL, 'pkg', 'Install pkg');
        $projectConfigStep = new InstallationStep(InstallationStep::TYPE_PROJECT_CONFIG, 'rebuild', 'Rebuild Project Config');
        $plan = new InstallationPlan([$pluginStep, $projectConfigStep], ['errors' => [], 'warnings' => []]);

        $pluginExecutor = $this->fakeStepExecutor('failed');
        $projectConfigExecutor = $this->fakeStepExecutor();
        $executor = new InstallationExecutor(['executorsByType' => [
            InstallationStep::TYPE_PLUGIN_INSTALL => $pluginExecutor,
            InstallationStep::TYPE_PROJECT_CONFIG => $projectConfigExecutor,
        ]]);

        $runner = new InstallationStageRunner([
            'planner' => $this->fakePlanner($plan),
            'validator' => $this->fakeValidator(new ValidationResult(true, [], [], [])),
            'executor' => $executor,
            'sessions' => $this->inMemorySessions(),
        ]);

        $session = $this->session();
        $result = $runner->runNextStage($session);

        $this->assertFalse($result['hasNextStage']);
        $this->assertSame(['composer', 'install'], $session->stagesCompleted);
        $this->assertSame(InstallationSession::STATUS_FAILED, $session->status);
        $this->assertSame([], $projectConfigExecutor->receivedSteps);
    }

    public function testANonCriticalFailureInTheInstallStageDoesNotBlockProjectConfig()
    {
        // Regression coverage for a live-testing discovery: STAGE_INSTALL
        // bundles a critical type (plugin-install) with non-critical types
        // (content, here) - a failed *content* step must not stop
        // project-config from running afterward, matching
        // InstallationExecutor's own single-process critical-type behavior.
        $contentStep = new InstallationStep(InstallationStep::TYPE_CONTENT, 'demo-kit', 'Install content');
        $projectConfigStep = new InstallationStep(InstallationStep::TYPE_PROJECT_CONFIG, 'rebuild', 'Rebuild Project Config');
        $plan = new InstallationPlan([$contentStep, $projectConfigStep], ['errors' => [], 'warnings' => []]);

        $contentExecutor = $this->fakeStepExecutor('failed');
        $projectConfigExecutor = $this->fakeStepExecutor();
        $executor = new InstallationExecutor(['executorsByType' => [
            InstallationStep::TYPE_CONTENT => $contentExecutor,
            InstallationStep::TYPE_PROJECT_CONFIG => $projectConfigExecutor,
        ]]);

        $runner = new InstallationStageRunner([
            'planner' => $this->fakePlanner($plan),
            'validator' => $this->fakeValidator(new ValidationResult(true, [], [], [])),
            'executor' => $executor,
            'sessions' => $this->inMemorySessions(),
        ]);

        $session = $this->session();
        $first = $runner->runNextStage($session);
        $this->assertTrue($first['hasNextStage']);
        $this->assertSame(['composer', 'install'], $session->stagesCompleted);

        $second = $runner->runNextStage($session);
        $this->assertFalse($second['hasNextStage']);
        $this->assertSame(['composer', 'install', 'project-config'], $session->stagesCompleted);
        $this->assertCount(1, $projectConfigExecutor->receivedSteps);
        $this->assertSame(InstallationSession::STATUS_FAILED, $session->status);
    }
}
