<?php

namespace site7\studio\tests\unit\models\commerce;

use Codeception\Test\Unit;
use site7\studio\models\commerce\PlanInfo;

class PlanInfoTest extends Unit
{
    protected \UnitTester $tester;

    public function testItReadsThePackageLimit(): void
    {
        $plan = new PlanInfo(['handle' => 'business', 'name' => 'Business', 'packageLimit' => 50, 'websiteLimit' => 20]);
        $this->assertSame(50, $plan->packageLimit);
        $this->assertSame(20, $plan->websiteLimit);
        $this->assertNull((new PlanInfo(['handle' => 'enterprise', 'name' => 'Enterprise']))->packageLimit, 'unlimited');
    }

    public function testKeysCommerce24AddsLaterAreIgnored(): void
    {
        $plan = new PlanInfo(['handle' => 'starter', 'name' => 'Starter', 'somethingNew' => true, 'priceMonthly' => 9]);
        $this->assertSame('starter', $plan->handle);
        $this->assertFalse(property_exists($plan, 'somethingNew'));
    }
}
