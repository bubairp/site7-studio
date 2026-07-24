<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use site7\studio\records\PackageDependencyRecord;
use site7\studio\records\PackageRecord;
use site7\studio\records\SharedResourceDependencyRecord;
use site7\studio\records\SharedResourceRecord;
use site7\studio\services\import\ResourceClassifierService;

/**
 * The Shared Resource Registry - the single source of truth for every Craft
 * resource intentionally reused across many packages (a Matrix field like
 * blockStyle, a shared field like button, a Volume, Category/Tag Group,
 * Global Set, or Navigation field). Packages never duplicate these; they
 * only ever reference them by handle (see PackageManifest's
 * dependencies.sharedResources).
 *
 * "Referenced By"/usage count for Package -> Shared Resource edges is
 * computed live from the existing site7_package_dependencies table
 * (dependencyType = 'sharedResource') - the same forward-edge-only
 * philosophy MarketplaceService::syncDependencyRecords() already uses for
 * package -> package dependencies. Nothing here duplicates that table.
 */
class SharedResourceRegistryService extends Component
{
    /**
     * Registers a Shared Resource if a row for this handle doesn't already
     * exist; otherwise refreshes its cached metadata (name/craftUid/craftId)
     * from the live values given, so a re-import or manual refresh always
     * reflects current Craft state without ever creating a duplicate row.
     */
    public function registerIfMissing(array $attributes): SharedResourceRecord
    {
        $record = SharedResourceRecord::find()->where(['handle' => $attributes['handle']])->one();
        if (!$record) {
            $record = new SharedResourceRecord();
            $record->handle = $attributes['handle'];
        }

        $record->name = $attributes['name'] ?? $record->name ?? $attributes['handle'];
        $record->type = $attributes['type'] ?? $record->type ?? 'field';
        $record->craftUid = $attributes['craftUid'] ?? $record->craftUid ?? null;
        $record->craftId = $attributes['craftId'] ?? $record->craftId ?? null;
        $record->version = $attributes['version'] ?? $record->version ?? '1.0.0';
        $record->installStatus = $attributes['installStatus'] ?? 'registered';
        if (array_key_exists('definitionSnapshot', $attributes)) {
            $record->definitionSnapshot = is_string($attributes['definitionSnapshot'])
                ? $attributes['definitionSnapshot']
                : json_encode($attributes['definitionSnapshot']);
        }

        $record->save();

        return $record;
    }

    public function getByHandle(string $handle): ?SharedResourceRecord
    {
        return SharedResourceRecord::find()->where(['handle' => $handle])->one();
    }

    /**
     * Registers a live Craft field classified as Shared Resource
     * (ResourceClassifierService::SHARED_RESOURCE) - the field itself is the
     * reusable resource (e.g. blockStyle, button). Scope note: for Assets/
     * Categories/Tags fields this registers the field, not the Volume/
     * Category Group/Tag Group it points at - a deliberate Phase 16
     * simplification (the field is still never duplicated either way);
     * resolving down to the underlying Volume/Group is left for a later
     * increment. No-op if the field isn't classified as a Shared Resource.
     *
     * Takes the already-resolved live Field object directly, rather than
     * re-looking it up by handle/UID here - getFieldByHandle() defaults to
     * the global field context and can silently miss fields whose real
     * context is scoped elsewhere (e.g. defined inline within a Matrix block
     * type's own field layout), and re-resolving by UID proved unreliable in
     * practice. The caller already has the correct object (it came from the
     * same field layout describeField() read), so it's passed straight
     * through instead of re-derived.
     */
    public function registerField(\craft\base\Field $field, array $classifiedField): void
    {
        if (($classifiedField['classification'] ?? null) !== ResourceClassifierService::SHARED_RESOURCE) {
            return;
        }

        $type = $field instanceof \craft\fields\Matrix ? 'matrix' : 'field';

        $this->registerIfMissing([
            'handle' => $field->handle,
            'name' => $field->name,
            'type' => $type,
            'craftUid' => $field->uid,
            'craftId' => $field->id,
        ]);
    }

    /**
     * @return SharedResourceRecord[]
     */
    public function getAll(): array
    {
        return SharedResourceRecord::find()->orderBy(['name' => SORT_ASC])->all();
    }

    /**
     * Every installed package that declares this Shared Resource in
     * manifest.dependencies.sharedResources (site7_package_dependencies,
     * dependencyType = 'sharedResource').
     *
     * @return PackageRecord[]
     */
    public function getDependentPackages(string $handle): array
    {
        $packageIds = PackageDependencyRecord::find()
            ->select('packageId')
            ->where(['dependencyType' => 'sharedResource', 'dependencyHandle' => $handle])
            ->column();

        if (empty($packageIds)) {
            return [];
        }

        return PackageRecord::find()->where(['id' => $packageIds])->all();
    }

    /**
     * Every other Shared Resource that depends on this one
     * (site7_shared_resource_dependencies).
     *
     * @return SharedResourceRecord[]
     */
    public function getDependentSharedResources(string $handle): array
    {
        $sharedResourceIds = SharedResourceDependencyRecord::find()
            ->select('sharedResourceId')
            ->where(['dependsOnHandle' => $handle])
            ->column();

        if (empty($sharedResourceIds)) {
            return [];
        }

        return SharedResourceRecord::find()->where(['id' => $sharedResourceIds])->all();
    }

    public function getUsageCount(string $handle): int
    {
        return count($this->getDependentPackages($handle)) + count($this->getDependentSharedResources($handle));
    }

    /**
     * Records that a Shared Resource depends on another (e.g. blockStyle ->
     * button). Idempotent - replaces this resource's existing dependency
     * rows rather than accumulating duplicates on repeated registration,
     * mirroring MarketplaceService::syncDependencyRecords()'s
     * delete-then-reinsert approach.
     */
    public function syncSharedDependencies(SharedResourceRecord $record, array $dependsOnHandles): void
    {
        SharedResourceDependencyRecord::deleteAll(['sharedResourceId' => $record->id]);

        foreach (array_unique($dependsOnHandles) as $handle) {
            if (!is_string($handle) || $handle === '' || $handle === $record->handle) {
                continue;
            }
            $dependency = new SharedResourceDependencyRecord();
            $dependency->sharedResourceId = $record->id;
            $dependency->dependsOnHandle = $handle;
            $dependency->dependencyType = 'sharedResource';
            $dependency->save();
        }
    }

    /**
     * Permanently deletes a Shared Resource registry row. Never deletes the
     * underlying live Craft resource itself (a Shared Resource always
     * pre-exists in Craft; this only removes it from the registry). Blocked
     * if anything still references it - see getUsageCount().
     *
     * @throws \Exception if the resource is still referenced by any package or other Shared Resource.
     */
    public function delete(string $handle): bool
    {
        $record = $this->getByHandle($handle);
        if (!$record) {
            return false;
        }

        if ($this->getUsageCount($handle) > 0) {
            throw new \Exception("Cannot delete '{$handle}' - it is still referenced by other packages or Shared Resources.");
        }

        return (bool)$record->delete();
    }
}
