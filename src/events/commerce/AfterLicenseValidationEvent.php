<?php

namespace site7\studio\events\commerce;

use site7\studio\events\BaseEvent;

/**
 * Dispatched by LicenseService::validateLicense() after a validation result is known.
 */
class AfterLicenseValidationEvent extends BaseEvent
{
    public bool $isValid = false;
}
