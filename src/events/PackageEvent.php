<?php

namespace site7\studio\events;

use yii\base\Event;
use site7\studio\records\PackageRecord;

/**
 * Class PackageEvent
 */
class PackageEvent extends Event
{
    /**
     * @var PackageRecord The package associated with the event.
     */
    public PackageRecord $package;

    /**
     * @var bool Whether the operation succeeded (used for AFTER events).
     */
    public bool $success = true;
}
