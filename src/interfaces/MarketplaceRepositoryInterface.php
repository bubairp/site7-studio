<?php

namespace site7\studio\interfaces;

use site7\studio\models\marketplace\MarketplaceListing;

/**
 * Contract for a source of distributable .s7pkg packages that Marketplace
 * can list and fetch from. Ships with a single implementation
 * (LocalMarketplaceRepository, a folder on this server) - future repository
 * types (remote, private, company, or the official Site7 Marketplace) plug
 * in by implementing this same interface and registering themselves with
 * MarketplaceService::registerRepository(), without any change to Marketplace
 * itself or the Package Engine underneath it.
 */
interface MarketplaceRepositoryInterface
{
    /**
     * A short, unique identifier for this repository (e.g. 'local').
     */
    public function getHandle(): string;

    /**
     * A human-readable label for this repository (e.g. "Local Repository").
     */
    public function getName(): string;

    /**
     * Lists every package this repository currently has available.
     *
     * @return MarketplaceListing[]
     */
    public function listAvailablePackages(): array;

    /**
     * Resolves a package handle (and optional version) to a local filesystem
     * path for a .s7pkg file that PackageImportService can validate/import.
     *
     * @throws \Exception if the package (or that version of it) isn't available.
     */
    public function fetchPackage(string $handle, ?string $version = null): string;
}
