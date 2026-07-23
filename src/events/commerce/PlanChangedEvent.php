<?php

namespace site7\studio\events\commerce;

use site7\studio\events\BaseEvent;
use site7\studio\models\commerce\PlanInfo;

/**
 * Dispatched by PlanService when the resolved current plan differs from the
 * previously cached one (i.e. an upgrade/downgrade actually took effect).
 */
class PlanChangedEvent extends BaseEvent
{
    public ?PlanInfo $previousPlan = null;
    public ?PlanInfo $newPlan = null;
}
