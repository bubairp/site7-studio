<?php

namespace site7\studio\interfaces;

use site7\studio\records\PackageVersionRecord;

/**
 * Semantic-version bumping and version history for a package - reuses
 * (never duplicates) the existing, previously-dormant site7_package_versions
 * table that MarketplaceService::recordVersion() already writes to on every
 * export/import; this interface's implementation is what starts actually
 * writing the releaseNotes column that table has always had but nothing
 * populated until now.
 */
interface VersionManagerInterface
{
    /**
     * Bumps $handle's manifest version per $bumpType ('patch'|'minor'|'major'),
     * writes it back to manifest.json (via PackageAuthoringService, same as
     * any other metadata edit), and records a PackageVersionRecord with
     * $releaseNotes. Dispatches VersionCreatedEvent.
     */
    public function createVersion(string $handle, string $bumpType, ?string $releaseNotes = null): PackageVersionRecord;

    /**
     * @return PackageVersionRecord[] newest first
     */
    public function getVersionHistory(string $handle): array;
}
