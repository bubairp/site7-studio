<?php

namespace site7\studio\events\publishing;

use site7\studio\events\BaseEvent;
use site7\studio\models\publishing\PublishResult;

/**
 * Dispatched by PackagePublisherService::publish() after a successful publish.
 */
class AfterPublishEvent extends BaseEvent
{
    public string $handle;
    public PublishResult $result;
}
