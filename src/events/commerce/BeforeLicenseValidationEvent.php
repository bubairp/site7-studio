<?php

namespace site7\studio\events\commerce;

use site7\studio\events\BaseEvent;

/**
 * Dispatched by LicenseService::validateLicense() before it calls Commerce24 (or
 * reads its cache). A subscriber can set $handled = true to short-circuit
 * validation entirely (e.g. in a test/offline harness).
 */
class BeforeLicenseValidationEvent extends BaseEvent
{
    public bool $handled = false;
    public bool $isValid = false;
}
