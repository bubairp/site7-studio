<?php

namespace site7\studio\events;

/**
 * Class PackageInstallEvent
 */
class PackageInstallEvent extends PackageEvent
{
    /**
     * @var \Throwable|null The exception that caused the installation to fail.
     */
    public ?\Throwable $exception = null;
}
