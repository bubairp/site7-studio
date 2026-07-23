<?php

namespace site7\studio\events\commerce;

use site7\studio\events\BaseEvent;

class PackageUpdatedEvent extends BaseEvent
{
    public string $handle;
    public ?string $fromVersion = null;
    public ?string $toVersion = null;
}
