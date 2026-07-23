<?php

namespace site7\studio\events\publishing;

use site7\studio\events\BaseEvent;

/**
 * Dispatched by PackagePublisherService::publish() once the target
 * repository for this publish attempt is resolved, before publishPackage()
 * is actually called on it.
 */
class RepositorySelectedEvent extends BaseEvent
{
    public string $handle;
    public string $repositoryHandle;
}
