<?php

namespace site7\studio\events\publishing;

use site7\studio\events\BaseEvent;
use site7\studio\records\PackageVersionRecord;

/**
 * Dispatched by VersionManagerInterface::createVersion() after a new version
 * is recorded.
 */
class VersionCreatedEvent extends BaseEvent
{
    public string $handle;
    public PackageVersionRecord $version;
}
