<?php

namespace site7\studio\events\publishing;

use site7\studio\events\BaseEvent;
use site7\studio\models\publishing\PublishReadinessResult;

/**
 * Dispatched by PackageValidatorInterface::validate() once a result is known.
 */
class AfterValidationEvent extends BaseEvent
{
    public string $handle;
    public PublishReadinessResult $result;
}
