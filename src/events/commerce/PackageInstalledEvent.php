<?php

namespace site7\studio\events\commerce;

use site7\studio\events\BaseEvent;

/**
 * Dispatched by the commerce PackageService after it installs an entitled
 * package through the existing PackageManagerService - a commerce-domain
 * sibling of the Package Engine's own dormant PackageEvent/PackageInstallEvent
 * (see PackageManagerService), for listeners concerned with entitlement/
 * download bookkeeping rather than the install lifecycle itself.
 */
class PackageInstalledEvent extends BaseEvent
{
    public string $handle;
}
