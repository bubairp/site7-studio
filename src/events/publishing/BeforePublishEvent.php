<?php

namespace site7\studio\events\publishing;

use site7\studio\events\BaseEvent;

/**
 * Dispatched by PackagePublisherService::publish() after validation/build
 * succeed, immediately before handing the archive to the chosen
 * PackagePublishTargetInterface. A subscriber can inspect (but not cancel -
 * see PublishFailedEvent for the failure path) the pending publish here.
 */
class BeforePublishEvent extends BaseEvent
{
    public string $handle;
    public string $repositoryHandle;
    public string $packagePath;
}
