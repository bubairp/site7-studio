<?php

namespace site7\studio\events\commerce;

use site7\studio\events\BaseEvent;

class LicenseDeactivatedEvent extends BaseEvent
{
    public ?string $licenseKey = null;
}
