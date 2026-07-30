<?php

namespace site7\studio\tests\unit\models\synchronization;

use Codeception\Test\Unit;
use site7\studio\models\synchronization\SynchronizationSession;

/**
 * Pure logic coverage for SynchronizationSession (Website Starter Kit
 * System Phase 8) - isDone() and the toArray()/fromArray() round trip (the
 * exact shape SynchronizationSessionService persists). No Craft app needed.
 */
class SynchronizationSessionTest extends Unit
{
    protected \UnitTester $tester;

    public function testIsDoneOnlyForCompletedOrFailedStatus()
    {
        $session = new SynchronizationSession('uid-1', 'demo-kit', '/tmp/demo-kit', '1.0.0', '1.1.0');
        $this->assertFalse($session->isDone());

        $session->status = SynchronizationSession::STATUS_EXECUTING;
        $this->assertFalse($session->isDone());

        $session->status = SynchronizationSession::STATUS_COMPLETED;
        $this->assertTrue($session->isDone());

        $session->status = SynchronizationSession::STATUS_FAILED;
        $this->assertTrue($session->isDone());
    }

    public function testToArrayAndFromArrayRoundTrip()
    {
        $session = new SynchronizationSession('uid-1', 'demo-kit', '/tmp/demo-kit', '1.0.0', '1.1.0', dryRun: true);
        $session->status = SynchronizationSession::STATUS_PLANNED;
        $session->plan = ['steps' => []];
        $session->confirmedRemovalKeys = ['categoryGroups:old'];
        $session->installationSessionUid = 'install-uid-1';

        $restored = SynchronizationSession::fromArray($session->toArray());

        $this->assertSame($session->uid, $restored->uid);
        $this->assertTrue($restored->dryRun);
        $this->assertSame(['categoryGroups:old'], $restored->confirmedRemovalKeys);
        $this->assertSame('install-uid-1', $restored->installationSessionUid);
        $this->assertSame(['steps' => []], $restored->plan);
    }
}
