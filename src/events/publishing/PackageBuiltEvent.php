<?php

namespace site7\studio\events\publishing;

use site7\studio\events\BaseEvent;

/**
 * Dispatched by PackageBuilderInterface::build() after a .s7pkg is written to disk.
 */
class PackageBuiltEvent extends BaseEvent
{
    public string $handle;
    public string $packagePath;
}
