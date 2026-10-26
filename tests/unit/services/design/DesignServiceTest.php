<?php

namespace site7\studio\tests\unit\services\design;

use Codeception\Test\Unit;
use site7\studio\services\design\DesignService;

class DesignServiceTest extends Unit
{
    protected \UnitTester $tester;

    /**
     * Canopy was withdrawn from Commerce24 (2026-10-26) and Dev's Appearance still showed
     * "Canopy, Not installed" from its old Library record.
     */
    public function testAWithdrawnDesignLeftInTheLibraryHasNoCard(): void
    {
        $this->assertTrue(DesignService::isWithdrawnLeftover(false, false, true, false), 'not installed, not here, no longer offered');
        $this->assertFalse(DesignService::isWithdrawnLeftover(true, false, true, false), 'installed: always shown');
        $this->assertFalse(DesignService::isWithdrawnLeftover(false, true, true, false), 'authored here (designs/<handle>): shown');
        $this->assertFalse(DesignService::isWithdrawnLeftover(false, false, true, true), 'still offered: Install brings it');
        $this->assertFalse(DesignService::isWithdrawnLeftover(false, false, false, false), 'Commerce24 unreachable: nothing hidden');
    }
}
