<?php

namespace site7\studio\services\synchronization;

use craft\base\Component;
use site7\studio\services\installation\StarterKitCatalogService;

/**
 * Read-only listing of installed Starter Kits that have a newer version
 * available in this site's packages/ directory (Website Starter Kit System
 * Phase 8, Update Wizard Step 1) - compares InstalledStarterKitTrackingService's
 * baseline against StarterKitCatalogService's own package scan (Phase 7,
 * reused unchanged) rather than re-scanning the filesystem itself.
 */
class UpdateCatalogService extends Component
{
    public ?StarterKitCatalogService $catalog = null;
    public ?InstalledStarterKitTrackingService $tracking = null;

    public function init(): void
    {
        parent::init();
        $this->catalog ??= new StarterKitCatalogService();
        $this->tracking ??= new InstalledStarterKitTrackingService();
    }

    /**
     * @return array<int, array{handle: string, name: string, installedVersion: string, availableVersion: string, path: string}>
     */
    public function listAvailableUpdates(): array
    {
        $updates = [];

        foreach ($this->tracking->allInstalled() as $installed) {
            $available = $this->findKit($installed['handle']);
            if ($available === null) {
                continue;
            }

            if (version_compare($available['version'], $installed['installedVersion'], '>')) {
                $updates[] = [
                    'handle' => $installed['handle'],
                    'name' => $available['name'],
                    'installedVersion' => $installed['installedVersion'],
                    'availableVersion' => $available['version'],
                    'path' => $available['path'],
                ];
            }
        }

        return $updates;
    }

    private function findKit(string $handle): ?array
    {
        foreach ($this->catalog->listAvailable() as $kit) {
            if ($kit['handle'] === $handle) {
                return $kit;
            }
        }
        return null;
    }
}
