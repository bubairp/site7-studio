<?php

namespace site7\studio\events\commerce;

use site7\studio\events\BaseEvent;

/**
 * Dispatched by UpdateService::checkUpdates() whenever the combined
 * plugin/package/security/dependency update check finds anything available.
 */
class UpdatesAvailableEvent extends BaseEvent
{
    /** @var array{plugin: array, packages: array, security: array, dependencies: array} */
    public array $updates = [];
}
