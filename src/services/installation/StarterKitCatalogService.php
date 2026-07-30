<?php

namespace site7\studio\services\installation;

use Craft;
use craft\base\Component;
use site7\studio\models\packages\StarterKitPackage;
use site7\studio\services\engine\PackageReader;

/**
 * Read-only listing of Starter Kit packages physically present in this
 * Craft installation's packages/ directory (Website Starter Kit System
 * Phase 7, Wizard Step 1) - not the Library's DB-backed package registry
 * (PackageDiscovery/PackageRegistryInterface), which is a different concern
 * for a site that has already installed packages to browse. A fresh target
 * site's packages/ directory holds Starter Kits waiting to be installed
 * (copied there exactly as Phase 6's own live verification did), so this
 * scans the filesystem directly via the existing PackageReader - no
 * registration, no validation side effects, nothing installed or mutated.
 */
class StarterKitCatalogService extends Component
{
    public ?PackageReader $reader = null;

    public function init(): void
    {
        parent::init();
        $this->reader ??= new PackageReader();
    }

    /**
     * @return array<int, array{handle: string, name: string, version: string, description: string, minimumCraftVersion: ?string, minimumPhpVersion: string, requiredPlugins: array, path: string, installable: bool, notes: string[]}>
     */
    public function listAvailable(): array
    {
        $packagesPath = rtrim(Craft::getAlias('@packages'), '/\\');
        if (!is_dir($packagesPath)) {
            return [];
        }

        $kits = [];
        foreach (scandir($packagesPath) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $packagePath = $packagesPath . DIRECTORY_SEPARATOR . $item;
            if (!is_dir($packagePath) || !is_file($packagePath . '/manifest.json')) {
                continue;
            }

            try {
                $package = $this->reader->readPackage($packagePath);
            } catch (\Throwable $e) {
                Craft::warning("Could not read package at {$packagePath}: " . $e->getMessage(), 'site7-studio');
                continue;
            }

            if (!($package instanceof StarterKitPackage)) {
                continue;
            }

            $kits[] = $this->describe($package->manifest, $packagePath);
        }

        return $kits;
    }

    /**
     * The package's own manifest.json version - distinct from anything in
     * blueprint.json, which has no version field of its own (a Blueprint
     * describes what to install, not which release it came from). Used by
     * Phase 8's InstalledStarterKitTrackingService to record what version
     * was actually installed, and by UpdateCatalogService to detect a
     * newer one is available.
     */
    public function getManifestVersion(string $handle): ?string
    {
        $manifestPath = $this->packagePath($handle) . '/manifest.json';
        if (!is_file($manifestPath)) {
            return null;
        }

        $data = json_decode((string)file_get_contents($manifestPath), true);
        return is_array($data) ? ($data['version'] ?? null) : null;
    }

    public function getBlueprint(string $handle): ?array
    {
        $blueprintPath = $this->packagePath($handle) . '/blueprint.json';
        if (!is_file($blueprintPath)) {
            return null;
        }

        $data = json_decode((string)file_get_contents($blueprintPath), true);
        return is_array($data) ? $data : null;
    }

    public function packagePath(string $handle): string
    {
        return rtrim(Craft::getAlias('@packages'), '/\\') . '/' . $handle;
    }

    private function describe(\site7\studio\models\packages\PackageManifest $manifest, string $packagePath): array
    {
        $blueprint = $this->getBlueprint($manifest->handle);
        $notes = [];

        if ($blueprint === null) {
            $notes[] = 'No blueprint.json found - this package was not built by the Starter Kit pipeline and cannot be installed by this wizard.';
        } elseif (($blueprint['validation']['valid'] ?? true) === false) {
            $notes[] = 'This package failed validation when it was built: ' . implode('; ', $blueprint['validation']['errors'] ?? []);
        }

        return [
            'handle' => $manifest->handle,
            'name' => $manifest->displayName ?: $manifest->name,
            'version' => $manifest->version,
            'description' => $manifest->description,
            'minimumCraftVersion' => $manifest->minimumCraftVersion,
            'minimumPhpVersion' => InstallationValidator::MIN_PHP_VERSION,
            'requiredPlugins' => $blueprint['requiredPlugins'] ?? ($manifest->dependencies['plugins'] ?? []),
            'path' => $packagePath,
            'installable' => $blueprint !== null && ($blueprint['validation']['valid'] ?? true) !== false,
            'notes' => $notes,
        ];
    }
}
