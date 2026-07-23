<?php

namespace site7\studio\events;

/**
 * Dispatched after PackageExportService successfully writes a .s7pkg
 * archive. Not yet subscribed to by anything in core - like PackageEvent/
 * PackageInstallEvent before it, this is an extension point for future
 * integrations (audit logging, a future remote Marketplace publish flow, etc.)
 * to hook without modifying PackageExportService itself.
 */
class PackageExportedEvent extends BaseEvent
{
    /** The handle of the package that was exported (the archive's root). */
    public string $handle;

    /** Absolute path to the generated .s7pkg file. */
    public string $path;

    /** Handles of every package bundled into the archive (root included). */
    public array $bundledHandles = [];
}
