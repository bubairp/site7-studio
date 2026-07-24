<?php

namespace site7\studio\events;

/**
 * Dispatched after a Resource Importer service (MatrixEntryTypeImportService,
 * CraftSectionImportService, PageImportService, WebsiteImportService)
 * successfully generates one or more packages from a live Craft resource.
 * Shape mirrors PackageImportedEvent - see that class's docblock.
 */
class ResourceImportedEvent extends BaseEvent
{
    /** One of: matrix-entry-type, craft-section, entry, website. */
    public string $sourceType;

    /** The id of the source Craft resource, or null when not applicable (e.g. website imports). */
    public ?int $sourceId = null;

    /** @var string[] Handles of every package produced by this import. */
    public array $packageHandles = [];

    /** Free-form summary data specific to the importer that dispatched this event. */
    public array $summary = [];
}
