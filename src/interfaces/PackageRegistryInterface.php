<?php

namespace site7\studio\interfaces;

use site7\studio\models\packages\Package;

/**
 * Interface PackageRegistryInterface
 *
 * Defines the contract for registering and retrieving packages.
 */
interface PackageRegistryInterface
{
    /**
     * Registers a package in the registry.
     *
     * @param Package $package
     */
    public function register(Package $package): void;

    /**
     * Gets a package by its handle.
     *
     * @param string $handle
     * @return Package|null
     */
    public function getPackage(string $handle): ?Package;

    /**
     * Returns all registered packages.
     *
     * @return Package[]
     */
    public function getAllPackages(): array;

    /**
     * Clears the registry.
     */
    public function clear(): void;
}
