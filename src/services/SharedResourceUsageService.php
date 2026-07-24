<?php

namespace site7\studio\services;

use craft\base\Component;
use site7\studio\Site7Studio;

/**
 * Mirrors PackageUsageService's role (used to block disable/remove/delete)
 * for Shared Resources - a single getUsage() call the CP delete action
 * checks before allowing a Shared Resource to be removed from the registry.
 * Delegates to SharedResourceRegistryService, which is the actual source of
 * truth for both edge types; this service exists only to give Shared
 * Resource delete-protection the same shape/name as PackageActionController's
 * existing `$usage = ...->getUsage($handle); if (!empty($usage)) {...}` check.
 */
class SharedResourceUsageService extends Component
{
    /**
     * @return array{packages: \site7\studio\records\PackageRecord[], sharedResources: \site7\studio\records\SharedResourceRecord[]}
     */
    public function getUsage(string $handle): array
    {
        $registry = Site7Studio::getInstance()->sharedResourceRegistry;

        return [
            'packages' => $registry->getDependentPackages($handle),
            'sharedResources' => $registry->getDependentSharedResources($handle),
        ];
    }

    public function isEmpty(array $usage): bool
    {
        return empty($usage['packages']) && empty($usage['sharedResources']);
    }
}
