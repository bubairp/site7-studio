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

        // If it is a pattern, verify and install required Sections first
        if ($record->type === 'pattern') {
            $manifest = $record->getManifest();
            if ($manifest && !empty($manifest->requires['sections'])) {
                foreach ($manifest->requires['sections'] as $requiredHandle) {
                    $requiredRecord = $this->getPackageByHandle($requiredHandle);

                    if (!$requiredRecord) {
                        // Attempt discovery to find newly added packages
                        $this->discoverPackages();
                        $requiredRecord = $this->getPackageByHandle($requiredHandle);
                    }

                    if ($requiredRecord) {
                        if ($requiredRecord->status !== 'enabled') {
                            if ($requiredRecord->status === 'available') {
                                $this->installPackage($requiredHandle);
                            }
                            $this->enablePackage($requiredHandle);
                        }
                    } else {
                        throw new \Exception("Required section package '{$requiredHandle}' was not found.");
                    }
                }
            }
        }

        // If it is a template, verify and install required Patterns and Sections first.
        // Templates are never stored as content and never generate their own Craft
        // resources - installing one only cascades into its required Patterns/Sections.
        // A required Pattern's own installPackage() call below already cascades into
        // its required Sections, so this achieves full transitive installation.
        if ($record->type === 'template') {
            $manifest = $record->getManifest();
            if ($manifest) {
                foreach (['patterns' => 'pattern', 'sections' => 'section'] as $requiresKey => $requiredKind) {
                    foreach ($manifest->requires[$requiresKey] ?? [] as $requiredHandle) {
                        $requiredRecord = $this->getPackageByHandle($requiredHandle);

                        if (!$requiredRecord) {
                            $this->discoverPackages();
                            $requiredRecord = $this->getPackageByHandle($requiredHandle);
                        }

                        if ($requiredRecord) {
                            if ($requiredRecord->status !== 'enabled') {
                                if ($requiredRecord->status === 'available') {
                                    $this->installPackage($requiredHandle);
                                }
                                $this->enablePackage($requiredHandle);
                            }
                        } else {
                            throw new \Exception("Required {$requiredKind} package '{$requiredHandle}' was not found.");
                        }
                    }
                }
            }
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
            if ($record->type === 'section' && is_dir($packagePath)) {
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

            $this->invalidateCraftCaches();

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
        $record = $this->getPackageByHandle($handle);
        if ($record && $record->type === 'section') {
            $this->linkToMatrix($handle);
        }
        $result = $this->updatePackageStatus($handle, 'enabled');
        $this->invalidateCraftCaches();
        return $result;
    }

    /**
     * Disables a package (updates status to 'disabled').
     */
    public function disablePackage(string $handle): bool
    {
        $record = $this->getPackageByHandle($handle);
        if ($record && $record->type === 'section') {
            $this->unlinkFromMatrix($handle);
        }
        $result = $this->updatePackageStatus($handle, 'disabled');
        $this->invalidateCraftCaches();
        return $result;
    }

    public function removePackage(string $handle): bool
    {
        $record = $this->getPackageByHandle($handle);
        if ($record && $record->type === 'section') {
            $this->unlinkFromMatrix($handle);
            
            // Remove resources
            $packagePath = $this->getPackagePath($handle);
            if ($packagePath && is_dir($packagePath)) {
                \site7\studio\Site7Studio::getInstance()->craftResourceGenerator->removePackageResources($packagePath);
            }
        }

        $result = $this->updatePackageStatus($handle, 'available');
        $this->invalidateCraftCaches();
        return $result;
    }
    /**
     * Invalidates all relevant Craft CMS internal caches after modifying
     * package resources or Matrix field configurations.
     *
     * This is critical for same-process operations (e.g. CLI tests that
     * install → enable → save content in one invocation). Craft caches
     * field instances in a private `_fields` property on the Fields service;
     * without clearing it the Matrix field retains its old entryTypes list
     * and silently drops blocks for newly linked types.
     */
    private function invalidateCraftCaches(): void
    {
        // 1. Rebuild project config YAML so the "Apply YAML Changes" banner never appears
        Craft::$app->getProjectConfig()->rebuild();

        // 2. Refresh the DB schema cache (new columns from new fields)
        Craft::$app->getDb()->getSchema()->refresh();

        // 3. Bump the field version counter
        Craft::$app->getFields()->updateFieldVersion();

        // 4. Refresh the entry types registry
        Craft::$app->getEntries()->refreshEntryTypes();

        // 5. Clear the private _fields cache on the Fields service so
        //    getFieldById() returns a completely fresh instance next time.
        //    There is no public API for this in Craft 5.
        try {
            $fieldsRef = new \ReflectionProperty(\craft\services\Fields::class, '_fields');
            $fieldsRef->setAccessible(true);
            $fieldsRef->setValue(Craft::$app->getFields(), null);
            
            $layoutsRef = new \ReflectionProperty(\craft\services\Fields::class, '_layouts');
            $layoutsRef->setAccessible(true);
            $layoutsRef->setValue(Craft::$app->getFields(), null);
        } catch (\ReflectionException $e) {
            Craft::warning('Could not clear Fields caches: ' . $e->getMessage(), __METHOD__);
        }

        // 6. Clear _entryTypes on any already-loaded Matrix field instances.
        //    Matrix.php caches entry types in a private _entryTypes array that
        //    is populated once at init. If the field object is held by something
        //    (e.g. a FieldLayout already stored on an Entry), the stale list
        //    causes _createEntriesFromSerializedData to silently skip new types.
        $settings = \site7\studio\Site7Studio::getInstance()->getSettings();
        if (!empty($settings->matrixFieldId)) {
            $matrixField = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
            if ($matrixField instanceof \craft\fields\Matrix) {
                // Re-populate _entryTypes from the fresh project config
                $matrixField->setEntryTypes(
                    array_map(fn($et) => $et->id, $matrixField->getEntryTypes())
                );
                // If the above produces the old list (because the field was just re-loaded
                // from cache), fall back to reading the project config directly
                try {
                    $ref = new \ReflectionProperty(\craft\fields\Matrix::class, '_entryTypes');
                    $ref->setAccessible(true);
                    $ref->setValue($matrixField, []);
                    // Force re-population from the DB by reading the field's settings
                    $fieldConfig = Craft::$app->getProjectConfig()->get("fields.{$matrixField->uid}");
                    if (isset($fieldConfig['settings']['entryTypes'])) {
                        $entryTypeIds = [];
                        foreach ($fieldConfig['settings']['entryTypes'] as $assocItem) {
                            if (isset($assocItem['__assoc__'])) {
                                foreach ($assocItem['__assoc__'] as [$key, $val]) {
                                    if ($key === 'uid') {
                                        $entryType = Craft::$app->getEntries()->getEntryTypeByUid($val);
                                        if ($entryType) {
                                            $entryTypeIds[] = $entryType;
                                        }
                                    }
                                }
                            }
                        }
                        if (!empty($entryTypeIds)) {
                            $ref->setValue($matrixField, $entryTypeIds);
                        }
                    }
                } catch (\ReflectionException $e) {
                    Craft::warning('Could not reset Matrix._entryTypes: ' . $e->getMessage(), __METHOD__);
                }
            }
        }

        // 7. Invalidate element query caches
        Craft::$app->getElements()->invalidateCachesForElementType(\craft\elements\Entry::class);
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
