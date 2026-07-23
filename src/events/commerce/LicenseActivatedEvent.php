<?php

namespace site7\studio\events\commerce;

use site7\studio\events\BaseEvent;
use site7\studio\models\commerce\LicenseInfo;

class LicenseActivatedEvent extends BaseEvent
{
    public LicenseInfo $license;
}
