<?php

namespace site7\studio\events;

/**
 * Dispatched after PackageImportService successfully installs a validated
 * .s7pkg archive. See PackageExportedEvent's docblock - same rationale.
 */
class PackageImportedEvent extends BaseEvent
{
    /** The handle of the archive's root package. */
    public string $rootHandle;

    /** {installed: string[], skipped: string[], errors: string[]} from PackageImportService::importPackage(). */
    public array $summary = [];
}
