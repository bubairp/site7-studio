<?php

namespace site7\studio\services\engine;

use craft\base\Component;
use site7\studio\interfaces\PackageRegistryInterface;

/**
 * PackageDiscovery service scans source directories for packages,
 * validates them, and registers them into the PackageRegistry.
 */
class PackageDiscovery extends Component
{
    /**
     * @var PackageReader
     */
    public PackageReader $reader;

    /**
     * @var PackageValidator
     */
    public PackageValidator $validator;

    /**
     * @var PackageRegistryInterface
     */
    public PackageRegistryInterface $registry;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        if (!isset($this->reader)) {
            $this->reader = new PackageReader();
        }
        if (!isset($this->validator)) {
            $this->validator = new PackageValidator();
        }
    }

    /**
     * Discover packages in a given directory path.
     * Expects the path to contain subdirectories which are themselves packages.
     *
     * @param string $sourcePath
     * @return int The number of valid packages discovered and registered.
     */
    public function discoverFromPath(string $sourcePath): int
    {
        $count = 0;
        
        if (!is_dir($sourcePath)) {
            return $count;
        }

        $items = scandir($sourcePath);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $packagePath = rtrim($sourcePath, '/\\') . DIRECTORY_SEPARATOR . $item;

            if (is_dir($packagePath)) {
                $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'manifest.json';
                if (file_exists($manifestPath)) {
                    try {
                        $package = $this->reader->readPackage($packagePath);
                        $this->validator->ensureValid($package);
                        $this->registry->register($package);
                        $count++;
                    } catch (\Exception $e) {
                        // Log the error and continue to the next package
                        \Craft::warning("Failed to discover package at {$packagePath}: " . $e->getMessage(), 'site7-studio');
                    }
                }
            }
        }

        return $count;
    }
}
