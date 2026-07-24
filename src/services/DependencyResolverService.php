<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use site7\studio\Site7Studio;

/**
 * The Dependency Engine. Resolves the two edge kinds Phase 16 introduces -
 * Shared Resource -> Shared Resource, and (any package) -> Shared Resource -
 * ahead of PackageManagerService::installPackage()'s existing, unmodified
 * per-type cascade (Pattern -> Section, Template -> Pattern/Section,
 * Starter Kit -> Template), which already resolves those edges correctly
 * and is left untouched here.
 */
class DependencyResolverService extends Component
{
    /**
     * @return array{sharedResources: array<int, array{handle: string, status: string}>, warnings: string[]}
     */
    public function resolvePackage(string $packageHandle): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($packageHandle);
        $manifest = $record?->getManifest();
        $handles = (array)($manifest->dependencies['sharedResources'] ?? []);

        return $this->resolveSharedResources($handles);
    }

    /**
     * Resolves an explicit list of Shared Resource handles, recursively
     * expanding each one's own Shared -> Shared dependencies.
     *
     * @param string[] $handles
     * @return array{sharedResources: array<int, array{handle: string, status: string}>, warnings: string[]}
     */
    public function resolveSharedResources(array $handles): array
    {
        $registry = Site7Studio::getInstance()->sharedResourceRegistry;
        $resolved = [];
        $warnings = [];
        $queue = array_values(array_unique($handles));
        $seen = [];

        while (!empty($queue)) {
            $handle = array_shift($queue);
            if ($handle === '' || isset($seen[$handle])) {
                continue;
            }
            $seen[$handle] = true;

            $registryRow = $registry->getByHandle($handle);
            if (!$registryRow) {
                $resolved[] = ['handle' => $handle, 'status' => 'missing'];
                $warnings[] = "Shared Resource '{$handle}' is not registered - install skipped for it (warning only, install continues). Resolve it from the Shared Resources Library (Import/Create/Skip).";
                continue;
            }

            $isLive = $this->isLive($registryRow->type, $registryRow->handle, $registryRow->craftUid);
            if (!$isLive) {
                $resolved[] = ['handle' => $handle, 'status' => 'missing'];
                $warnings[] = "Shared Resource '{$handle}' is registered but no longer exists in Craft - install continues, resolve it from the Shared Resources Library.";
                continue;
            }

            $resolved[] = ['handle' => $handle, 'status' => 'linked'];

            foreach ($this->getSharedDependencyHandles($handle) as $childHandle) {
                if (!isset($seen[$childHandle])) {
                    $queue[] = $childHandle;
                }
            }
        }

        return ['sharedResources' => $resolved, 'warnings' => $warnings];
    }

    /**
     * @return string[]
     */
    private function getSharedDependencyHandles(string $handle): array
    {
        $registryRow = Site7Studio::getInstance()->sharedResourceRegistry->getByHandle($handle);
        if (!$registryRow) {
            return [];
        }
        return array_map(fn($dep) => $dep->dependsOnHandle, $registryRow->getDependencies()->all());
    }

    private function isLive(string $type, string $handle, ?string $craftUid = null): bool
    {
        // getFieldByHandle() defaults to the global field context and can
        // miss fields whose real context is scoped elsewhere (e.g. defined
        // inline within a Matrix block type's own field layout) - this is
        // only a liveness re-check (never writes data), so trying the stored
        // craftUid as a second lookup is safe here even though resolving a
        // field to *register* by UID proved unreliable (see
        // SharedResourceRegistryService::registerField()'s docblock).
        return match ($type) {
            'field', 'matrix' => (bool)Craft::$app->getFields()->getFieldByHandle($handle)
                || (bool)($craftUid ? Craft::$app->getFields()->getFieldByUid($craftUid) : false),
            'entryType' => (bool)Craft::$app->getEntries()->getEntryTypeByHandle($handle),
            'volume' => (bool)Craft::$app->getVolumes()->getVolumeByHandle($handle),
            'categoryGroup' => (bool)Craft::$app->getCategories()->getGroupByHandle($handle),
            'tagGroup' => (bool)Craft::$app->getTags()->getTagGroupByHandle($handle),
            'globalSet' => (bool)Craft::$app->getGlobals()->getSetByHandle($handle),
            default => true, // navigation/other types have no single canonical live-lookup - trust the registry
        };
    }
}
