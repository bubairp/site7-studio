<?php

namespace site7\studio\services;

use craft\base\Component;
use site7\studio\repositories\PackageRepository;
use site7\studio\services\engine\PackageDiscovery;
use site7\studio\registries\MemoryPackageRegistry;
use site7\studio\records\PackageRecord;
use Craft;

/**
 * PackageManagerService manages the high-level interactions with packages.
 * It is responsible for orchestrating discovery and loading packages from the repository.
 */
class PackageManagerService extends Component
{
    /**
     * @var PackageRepository
     */
    public PackageRepository $repository;

    /**
     * @var PackageDiscovery
     */
    public PackageDiscovery $discovery;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        if (!isset($this->repository)) {
            $this->repository = new PackageRepository();
        }
        if (!isset($this->discovery)) {
            $this->discovery = new PackageDiscovery();
            $this->discovery->registry = new MemoryPackageRegistry();
            $this->discovery->init();
        }
    }

    /**
     * Discovers all packages from configured sources and persists them to the repository.
     * This fulfills the requirement: PackageDiscovery -> PackageReader -> PackageValidator -> PackageRepository
     *
     * @return int Number of packages discovered and saved.
     */
    public function discoverPackages(): int
    {
        // Define our primary local library source
        // Ideally this comes from a LibrarySourceInterface, but for now we'll use the plugins dir or a configurable path.
        // We can just scan the built-in packages directory or the test fixtures for now if no built-in exists.
        // Actually, let's scan a 'packages' folder in the craft root or site7-studio root.
        $pluginPath = Craft::getAlias('@site7/studio');
        $packagesPath = dirname($pluginPath) . '/packages'; // /plugins/site7-studio/packages
        
        // Let's also check the tests/fixtures/packages path for testing
        $testPath = $pluginPath . '/tests/fixtures/packages';
        
        $pathsToScan = [$packagesPath, $testPath];
        
        $totalDiscovered = 0;

        foreach ($pathsToScan as $path) {
            if (is_dir($path)) {
                $count = $this->discovery->discoverFromPath($path);
                $totalDiscovered += $count;
            }
        }

        // Save everything discovered in the registry into the DB repository
        $packages = $this->discovery->registry->getAllPackages();
        foreach ($packages as $package) {
            $this->repository->save($package);
        }

        // Clear registry to free memory
        $this->discovery->registry->clear();

        return $totalDiscovered;
    }

    /**
     * Returns all packages from the repository.
     * Discovers them first if none exist, or on every call (since we're in dev).
     *
     * @return PackageRecord[]
     */
    public function getAllPackages(): array
    {
        // For development, we'll sync packages on every load to ensure the DB matches the filesystem.
        $this->discoverPackages();
        return $this->repository->findAll();
    }

    /**
     * Gets a package by its handle from the repository.
     *
     * @param string $handle
     * @return PackageRecord|null
     */
    public function getPackageByHandle(string $handle): ?PackageRecord
    {
        return $this->repository->findByHandle($handle);
    }

    /**
     * Gets the absolute path to a package directory.
     *
     * @param string $handle
     * @return string|null
     */
    public function getPackagePath(string $handle): ?string
    {
        $pluginPath = Craft::getAlias('@site7/studio');
        $basePath = dirname($pluginPath);
        $packagePath = $basePath . '/packages/' . $handle;
        if (!is_dir($packagePath)) {
            $packagePath = $basePath . '/tests/fixtures/packages/' . $handle;
        }
        return is_dir($packagePath) ? $packagePath : null;
    }

    /**
     * Installs a package.
     */
    public function installPackage(string $handle): bool
    {
        $record = $this->getPackageByHandle($handle);
        if (!$record) {
            return false;
        }

        // We assume the package is in our local source for MVP
        $pluginPath = Craft::getAlias('@site7/studio'); // resolves to src/
        $basePath = dirname($pluginPath); // resolves to plugins/site7-studio/
        $packagePath = $basePath . '/packages/' . $handle;
        if (!is_dir($packagePath)) {
            $packagePath = $basePath . '/tests/fixtures/packages/' . $handle;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        $generatedResources = [];
        try {
            // 1. Generate Craft Resources
            if (is_dir($packagePath)) {
                $generatedResources = \site7\studio\Site7Studio::getInstance()->craftResourceGenerator->generateResources($packagePath);
                
                // Save generated resource UIDs somewhere? For MVP, we will rely on handle conventions
            }

            // NOTE: Install does NOT link to Matrix. User must click "Enable" to do that.

            // 2. Update status
            $record->status = 'installed';
            if (!$record->save()) {
                throw new \Exception("Could not save package status.");
            }

            $transaction->commit();

            // Auto-sync project config so "Apply YAML Changes" banner never appears
            Craft::$app->getProjectConfig()->rebuild();

            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            // Rollback generated project config resources
            \site7\studio\Site7Studio::getInstance()->craftResourceGenerator->removeResources($generatedResources);
            Craft::error("Installation failed for {$handle}: " . $e->getMessage(), __METHOD__);
            echo "Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            return false;
        }
    }

    /**
     * Enables a package (updates status to 'enabled').
     */
    public function enablePackage(string $handle): bool
    {
        $this->linkToMatrix($handle);
        $result = $this->updatePackageStatus($handle, 'enabled');
        Craft::$app->getProjectConfig()->rebuild();
        return $result;
    }

    /**
     * Disables a package (updates status to 'disabled').
     */
    public function disablePackage(string $handle): bool
    {
        $this->unlinkFromMatrix($handle);
        $result = $this->updatePackageStatus($handle, 'disabled');
        Craft::$app->getProjectConfig()->rebuild();
        return $result;
    }

    /**
     * Removes a package (updates status to 'available').
     */
    public function removePackage(string $handle): bool
    {
        $this->unlinkFromMatrix($handle);
        
        // Remove resources
        $packagePath = $this->getPackagePath($handle);
        if ($packagePath && is_dir($packagePath)) {
            \site7\studio\Site7Studio::getInstance()->craftResourceGenerator->removePackageResources($packagePath);
        }

        $result = $this->updatePackageStatus($handle, 'available');
        Craft::$app->getProjectConfig()->rebuild();
        return $result;
    }

    /**
     * Helper to update the status of a package in the repository.
     */
    private function updatePackageStatus(string $handle, string $status): bool
    {
        $record = $this->getPackageByHandle($handle);
        if ($record) {
            $record->status = $status;
            return $record->save();
        }
        return false;
    }

    /**
     * Links a package's Entry Types to the configured Matrix field.
     */
    public function linkToMatrix(string $handle): void
    {
        $this->modifyMatrixLink($handle, true);
    }

    /**
     * Unlinks a package's Entry Types from the configured Matrix field.
     */
    public function unlinkFromMatrix(string $handle): void
    {
        $this->modifyMatrixLink($handle, false);
    }

    private function modifyMatrixLink(string $handle, bool $add): void
    {
        $settings = \site7\studio\Site7Studio::getInstance()->getSettings();
        if (!$settings->matrixFieldId) {
            return;
        }

        $fieldsService = Craft::$app->getFields();
        $matrixField = $fieldsService->getFieldById($settings->matrixFieldId);
        
        if (!$matrixField || !($matrixField instanceof \craft\fields\Matrix)) {
            return;
        }

        $packagePath = $this->getPackagePath($handle);
        if (!$packagePath) {
            return;
        }

        $matrixYamlPath = $packagePath . '/matrix.yaml';
        if (!file_exists($matrixYamlPath)) {
            return;
        }

        $matrixData = \Symfony\Component\Yaml\Yaml::parseFile($matrixYamlPath);
        if (!isset($matrixData['blocks']) || !is_array($matrixData['blocks'])) {
            return;
        }

        $entriesService = Craft::$app->getEntries();
        $existingEntryTypes = $matrixField->getEntryTypes();
        $entryTypeIds = array_map(fn($et) => $et->id, $existingEntryTypes);

        $changed = false;

        foreach ($matrixData['blocks'] as $blockDef) {
            $blockHandle = $blockDef['handle'] ?? null;
            if (!$blockHandle) continue;

            $entryType = $entriesService->getEntryTypeByHandle($blockHandle);
            if (!$entryType) continue;

            if ($add && !in_array($entryType->id, $entryTypeIds)) {
                $entryTypeIds[] = $entryType->id;
                $changed = true;
            } elseif (!$add && in_array($entryType->id, $entryTypeIds)) {
                $entryTypeIds = array_diff($entryTypeIds, [$entryType->id]);
                $changed = true;
            }
        }

        if ($changed) {
            $matrixField->setEntryTypes($entryTypeIds);
            $fieldsService->saveField($matrixField);
        }
    }
}
