<?php

namespace site7\studio\events\publishing;

use site7\studio\events\BaseEvent;

/**
 * Dispatched by PackageValidatorInterface::validate() before running its checks.
 */
class BeforeValidationEvent extends BaseEvent
{
    public string $handle;
}
