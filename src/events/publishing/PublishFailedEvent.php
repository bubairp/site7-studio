<?php

namespace site7\studio\events\publishing;

use site7\studio\events\BaseEvent;

/**
 * Dispatched by PackagePublisherService::publish() whenever any step
 * (validation, build, or the target's own publishPackage()) fails.
 */
class PublishFailedEvent extends BaseEvent
{
    public string $handle;
    public string $reason;
}
