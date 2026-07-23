<?php

namespace site7\studio\events\publishing;

use site7\studio\events\BaseEvent;

/**
 * Extension point only, per Phase 14's "Digital Signature" scope - never
 * actually dispatched today, since NullPackageSigner never produces a real
 * signature. Reserved for when a real PackageSignerInterface implementation
 * exists.
 */
class PackageSignedEvent extends BaseEvent
{
    public string $handle;
    public ?string $signature = null;
}
