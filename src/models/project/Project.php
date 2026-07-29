<?php

namespace site7\studio\models\project;

use site7\studio\models\packages\PackageManifest;
use site7\studio\records\PackageRecord;
use site7\studio\services\CraftResourceRegistry;

/**
 * The complete, assembled representation of a captured website (Website
 * Starter Kit System Phase 5) - ProjectBuilder's output. Unifies what the
 * independent Phase 1-4 capture systems each produced into one object:
 *
 * - $packageRecord/$manifest: the already-written Starter Kit package
 *   (pages, globals, craftSections, assetVolumes, categoryGroups, tagGroups,
 *   navigation, dependencies.plugins/npmPackages/frontendTooling) - Website
 *   Import's own result, unchanged.
 * - $registry: the CraftResourceRegistry instance WebsiteImportService used
 *   while capturing, kept alive here so DependencyAnalyzer can traverse the
 *   same resource graph without re-scanning Craft.
 * - $platformConfiguration: computed fresh by ProjectBuilder - which
 *   captured fields are Platform Configuration (Phase 3's
 *   PlatformConfigService), aggregated project-wide rather than noted
 *   per-field as ResourceClassifierService does today.
 * - $skipped/$notes: passed through from WebsiteImportService::importWebsite()
 *   unchanged, so nothing about the underlying capture's own diagnostics is lost.
 *
 * A plain read-only data holder - it doesn't itself compute or capture
 * anything (that's ProjectBuilder's job) and it never mutates a Craft
 * resource.
 */
final class Project
{
    public function __construct(
        public readonly PackageRecord $packageRecord,
        public readonly PackageManifest $manifest,
        public readonly CraftResourceRegistry $registry,
        /** @var array{categories: string[], fields: array<int, array{handle: string, name: string, category: string}>} */
        public readonly array $platformConfiguration,
        /** @var string[] */
        public readonly array $skipped,
        /** @var string[] */
        public readonly array $notes,
    ) {
    }

    /**
     * The package's own directory on disk - reconstructed the same way
     * every other package-path consumer in this plugin does (PackageRecord
     * itself doesn't expose a $path property).
     */
    public function packagePath(): string
    {
        return rtrim(\Craft::getAlias('@packages'), '/') . '/' . $this->manifest->handle;
    }
}
