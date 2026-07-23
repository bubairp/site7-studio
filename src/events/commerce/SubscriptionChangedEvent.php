<?php

namespace site7\studio\events\commerce;

use site7\studio\events\BaseEvent;
use site7\studio\models\commerce\SubscriptionInfo;

/**
 * Dispatched by SubscriptionService after upgrade()/downgrade()/renew()/cancel()
 * successfully change the subscription's state.
 */
class SubscriptionChangedEvent extends BaseEvent
{
    public SubscriptionInfo $subscription;
    public ?string $previousStatus = null;
}
