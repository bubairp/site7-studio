<?php

namespace site7\studio\registries;

use craft\base\Component;
use site7\studio\interfaces\PackageRegistryInterface;
use site7\studio\models\packages\Package;

/**
 * MemoryPackageRegistry is an in-memory implementation of the PackageRegistryInterface.
 * It is used for discovery and validation before persistence is available (Milestone 4.1).
 */
class MemoryPackageRegistry extends Component implements PackageRegistryInterface
{
    /**
     * @var Package[]
     */
    private array $packages = [];

    /**
     * @inheritdoc
     */
    public function register(Package $package): void
    {
        $handle = $package->manifest->handle;
        $this->packages[$handle] = $package;
    }

    /**
     * @inheritdoc
     */
    public function getPackage(string $handle): ?Package
    {
        return $this->packages[$handle] ?? null;
    }

    /**
     * @inheritdoc
     */
    public function getAllPackages(): array
    {
        return array_values($this->packages);
    }

    /**
     * @inheritdoc
     */
    public function clear(): void
    {
        $this->packages = [];
    }
}
