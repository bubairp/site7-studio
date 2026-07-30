<?php

namespace site7\studio\tests\unit\models\installation;

use Codeception\Test\Unit;
use site7\studio\models\installation\InstallationSession;

/**
 * Pure logic coverage for InstallationSession (Website Starter Kit System
 * Phase 7) - stage sequencing, log/result bookkeeping, and array round-trip
 * (the shape InstallationSessionService actually persists). No Craft app
 * needed - this class has no dependency on one.
 */
class InstallationSessionTest extends Unit
{
    protected \UnitTester $tester;

    public function testNextStageWalksTheFixedStageOrder()
    {
        $session = new InstallationSession('uid-1', 'demo-kit', '/tmp/demo-kit');

        $this->assertSame('composer', $session->nextStage());

        $session->stagesCompleted[] = 'composer';
        $this->assertSame('install', $session->nextStage());

        $session->stagesCompleted[] = 'install';
        $this->assertSame('project-config', $session->nextStage());

        $session->stagesCompleted[] = 'project-config';
        $this->assertNull($session->nextStage());
    }

    public function testIsDoneOnlyForCompletedOrFailedStatus()
    {
        $session = new InstallationSession('uid-1', 'demo-kit', '/tmp/demo-kit');
        $this->assertFalse($session->isDone());

        $session->status = InstallationSession::STATUS_EXECUTING;
        $this->assertFalse($session->isDone());

        $session->status = InstallationSession::STATUS_COMPLETED;
        $this->assertTrue($session->isDone());

        $session->status = InstallationSession::STATUS_FAILED;
        $this->assertTrue($session->isDone());
    }

    public function testAppendLogAccumulatesInOrder()
    {
        $session = new InstallationSession('uid-1', 'demo-kit', '/tmp/demo-kit');
        $session->appendLog('composer', 'vendor/pkg', 'Require vendor/pkg', 'completed', null);
        $session->appendLog('plugin-install', 'pkg', 'Install pkg', 'failed', 'boom');

        $this->assertCount(2, $session->progressLog);
        $this->assertSame('completed', $session->progressLog[0]['status']);
        $this->assertSame('boom', $session->progressLog[1]['message']);
    }

    public function testMergedStageResultsCombinesEveryStageWithoutDuplicatingWarnings()
    {
        $session = new InstallationSession('uid-1', 'demo-kit', '/tmp/demo-kit');
        $session->stageResults[] = ['completedSteps' => [['a']], 'skippedSteps' => [], 'failedSteps' => [], 'errors' => []];
        $session->stageResults[] = ['completedSteps' => [['b']], 'skippedSteps' => [['c']], 'failedSteps' => [], 'errors' => ['boom']];

        $merged = $session->mergedStageResults();

        $this->assertCount(2, $merged['completedSteps']);
        $this->assertCount(1, $merged['skippedSteps']);
        $this->assertSame(['boom'], $merged['errors']);
        $this->assertArrayNotHasKey('warnings', $merged);
    }

    public function testToArrayAndFromArrayRoundTrip()
    {
        $session = new InstallationSession('uid-1', 'demo-kit', '/tmp/demo-kit', dryRun: true);
        $session->status = InstallationSession::STATUS_EXECUTING;
        $session->stagesCompleted = ['composer'];
        $session->appendLog('composer', 'vendor/pkg', 'Require vendor/pkg', 'completed', null);

        $restored = InstallationSession::fromArray($session->toArray());

        $this->assertSame($session->uid, $restored->uid);
        $this->assertTrue($restored->dryRun);
        $this->assertSame(['composer'], $restored->stagesCompleted);
        $this->assertCount(1, $restored->progressLog);
    }
}
