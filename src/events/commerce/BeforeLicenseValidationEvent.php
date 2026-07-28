<?php

namespace site7\studio\events\commerce;

use site7\studio\events\BaseEvent;

/**
 * Dispatched by LicenseService::validateLicense() before it calls Commerce24 (or
 * reads its cache). A subscriber can set $shortCircuited = true to short-circuit
 * validation entirely (e.g. in a test/offline harness).
 *
 * Named $shortCircuited, not $handled - yii\base\Event (which BaseEvent
 * extends) already declares its own public bool-incompatible $handled
 * property; redeclaring it here is a PHP fatal compile error ("Type of X::
 * $handled must not be defined"), invisible to `php -l` and only surfacing
 * when this class is loaded. See BaseEvent-derived classes for the same
 * trap on $name/$sender/$data.
 */
class BeforeLicenseValidationEvent extends BaseEvent
{
    public bool $shortCircuited = false;
    public bool $isValid = false;
}
