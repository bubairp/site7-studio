<?php

namespace site7\studio\services\library;

/**
 * What PackageFileUpdater needs from file tracking (site7_installed_files):
 * InstalledFileBaselineStore in production, an in-memory store in unit tests.
 * Paths are relative to the Craft root, e.g. templates/_blocks/ctaBanner.twig.
 */
interface FileBaselineStore
{
    /** @return array{checksum: string, installedVersion: string}|null */
    public function find(int $packageId, string $targetPath): ?array;

    public function record(int $packageId, string $resourceHandle, string $targetPath, string $version, string $checksum): void;

    public function remove(int $packageId, string $targetPath): void;

    /** Handle of another package that tracks $targetPath, or null */
    public function otherOwner(int $packageId, string $targetPath): ?string;
}
