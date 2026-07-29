<?php

namespace site7\studio\tests\unit\services\installation;

use Codeception\Test\Unit;
use site7\studio\interfaces\StepExecutorInterface;
use site7\studio\models\installation\InstallationPlan;
use site7\studio\models\installation\InstallationStep;
use site7\studio\models\installation\ValidationResult;
use site7\studio\services\installation\InstallationExecutor;

/**
 * Covers InstallationExecutor's orchestration logic (grouping steps by
 * type, stopping on a critical-type failure, report shape) using fake
 * StepExecutorInterface implementations rather than any real executor or a
 * live Craft app - InstallationExecutor's own $executorsByType property is
 * public and Yii-config-constructible specifically for this (Website
 * Starter Kit System Phase 6).
 */
class InstallationExecutorTest extends Unit
{
    protected \UnitTester $tester;

    private function fakeExecutor(array $resultsToReturn): StepExecutorInterface
    {
        return new class ($resultsToReturn) implements StepExecutorInterface {
            public array $receivedSteps = [];
            public ?bool $receivedDryRun = null;

            public function __construct(private readonly array $resultsToReturn)
            {
            }

            public function execute(array $steps, bool $dryRun): array
            {
                $this->receivedSteps = $steps;
                $this->receivedDryRun = $dryRun;
                return $this->resultsToReturn;
            }
        };
    }

    private function passingValidation(): ValidationResult
    {
        return new ValidationResult(true, [], [], []);
    }

    public function testAllStepsCompletedYieldsASuccessfulReport()
    {
        $step = new InstallationStep(InstallationStep::TYPE_CONTENT, 'kit', 'Install content');
        $executor = $this->fakeExecutor([['step' => $step, 'status' => 'completed', 'message' => 'done']]);

        $installationExecutor = new InstallationExecutor(['executorsByType' => [InstallationStep::TYPE_CONTENT => $executor]]);
        $report = $installationExecutor->execute(new InstallationPlan([$step], ['errors' => [], 'warnings' => []]), $this->passingValidation());

        $this->assertTrue($report->success);
        $this->assertCount(1, $report->completedSteps);
        $this->assertSame([], $report->failedSteps);
    }

    public function testFailingAValidationResultSkipsExecutionEntirely()
    {
        $step = new InstallationStep(InstallationStep::TYPE_CONTENT, 'kit', 'Install content');
        $executor = $this->fakeExecutor([['step' => $step, 'status' => 'completed', 'message' => null]]);

        $installationExecutor = new InstallationExecutor(['executorsByType' => [InstallationStep::TYPE_CONTENT => $executor]]);
        $invalidResult = new ValidationResult(false, ['something is wrong'], [], []);
        $report = $installationExecutor->execute(new InstallationPlan([$step], ['errors' => [], 'warnings' => []]), $invalidResult);

        $this->assertFalse($report->success);
        $this->assertSame([], $report->completedSteps);
        $this->assertContains('something is wrong', $report->errors);
        // The fake executor was never even called.
        $this->assertSame([], $executor->receivedSteps);
    }

    public function testACriticalStepFailureStopsAndSkipsSubsequentSteps()
    {
        $composerStep = new InstallationStep(InstallationStep::TYPE_COMPOSER, 'vendor/pkg', 'Require vendor/pkg');
        $contentStep = new InstallationStep(InstallationStep::TYPE_CONTENT, 'kit', 'Install content');

        $composerExecutor = $this->fakeExecutor([['step' => $composerStep, 'status' => 'failed', 'message' => 'composer blew up']]);
        $contentExecutor = $this->fakeExecutor([['step' => $contentStep, 'status' => 'completed', 'message' => null]]);

        $installationExecutor = new InstallationExecutor(['executorsByType' => [
            InstallationStep::TYPE_COMPOSER => $composerExecutor,
            InstallationStep::TYPE_CONTENT => $contentExecutor,
        ]]);
        $report = $installationExecutor->execute(
            new InstallationPlan([$composerStep, $contentStep], ['errors' => [], 'warnings' => []]),
            $this->passingValidation()
        );

        $this->assertFalse($report->success);
        $this->assertCount(1, $report->failedSteps);
        $this->assertCount(1, $report->skippedSteps);
        $this->assertSame($contentStep->key, $report->skippedSteps[0]['step']['key']);
        // The content executor was never invoked - the plan stopped before reaching it.
        $this->assertSame([], $contentExecutor->receivedSteps);
    }

    public function testANonCriticalStepFailureDoesNotStopSubsequentSteps()
    {
        $resourceStep = new InstallationStep(InstallationStep::TYPE_CRAFT_RESOURCE, 'assetVolume:images', 'Create Asset Volume');
        $contentStep = new InstallationStep(InstallationStep::TYPE_CONTENT, 'kit', 'Install content');

        $resourceExecutor = $this->fakeExecutor([['step' => $resourceStep, 'status' => 'failed', 'message' => 'volume failed']]);
        $contentExecutor = $this->fakeExecutor([['step' => $contentStep, 'status' => 'completed', 'message' => null]]);

        $installationExecutor = new InstallationExecutor(['executorsByType' => [
            InstallationStep::TYPE_CRAFT_RESOURCE => $resourceExecutor,
            InstallationStep::TYPE_CONTENT => $contentExecutor,
        ]]);
        $report = $installationExecutor->execute(
            new InstallationPlan([$resourceStep, $contentStep], ['errors' => [], 'warnings' => []]),
            $this->passingValidation()
        );

        $this->assertFalse($report->success);
        $this->assertCount(1, $report->failedSteps);
        $this->assertCount(1, $report->completedSteps);
        // The content executor WAS invoked despite the earlier non-critical failure.
        $this->assertCount(1, $contentExecutor->receivedSteps);
    }

    public function testDryRunFlagIsPassedThroughToEachExecutor()
    {
        $step = new InstallationStep(InstallationStep::TYPE_CONTENT, 'kit', 'Install content');
        $executor = $this->fakeExecutor([['step' => $step, 'status' => 'skipped', 'message' => 'dry run']]);

        $installationExecutor = new InstallationExecutor(['executorsByType' => [InstallationStep::TYPE_CONTENT => $executor]]);
        $installationExecutor->execute(new InstallationPlan([$step], ['errors' => [], 'warnings' => []]), $this->passingValidation(), dryRun: true);

        $this->assertTrue($executor->receivedDryRun);
    }
}
