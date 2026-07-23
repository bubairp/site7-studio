<?php

namespace site7\studio\events\commerce;

use site7\studio\events\BaseEvent;

class PackageRemovedEvent extends BaseEvent
{
    public string $handle;
}
