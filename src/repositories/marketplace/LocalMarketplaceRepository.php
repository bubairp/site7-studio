<?php

namespace site7\studio\repositories\marketplace;

use Craft;
use craft\helpers\FileHelper;
use site7\studio\interfaces\MarketplaceRepositoryInterface;
use site7\studio\models\marketplace\MarketplaceListing;
use site7\studio\services\support\PackageArchiveHelper;

/**
 * The only repository implementation shipped with the Marketplace
 * Foundation: a plain folder on this server
 * (storage/site7-studio/marketplace-repo/) that .s7pkg files can be dropped
 * into to make them installable from the Marketplace's Repository/Updates
 * tabs. Deliberately has no network/auth/publish concerns - those belong to
 * future repository types (remote, private, company, or the official Site7
 * Marketplace), which register alongside this one by implementing the same
 * MarketplaceRepositoryInterface.
 */
class LocalMarketplaceRepository implements MarketplaceRepositoryInterface
{
    /**
     * @inheritdoc
     */
    public function getHandle(): string
    {
        return 'local';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Local Repository';
    }

    /**
     * @inheritdoc
     */
    public function listAvailablePackages(): array
    {
        $listings = [];

        foreach (glob($this->getRepositoryPath() . '/*.s7pkg') ?: [] as $file) {
            try {
                $listings[] = $this->readListing($file);
            } catch (\Throwable $e) {
                Craft::warning("Skipping unreadable .s7pkg in the Local Repository ({$file}): " . $e->getMessage(), 'site7-studio');
            }
        }

        return $listings;
    }

    /**
     * @inheritdoc
     */
    public function fetchPackage(string $handle, ?string $version = null): string
    {
        foreach ($this->listAvailablePackages() as $listing) {
            if ($listing->handle === $handle && ($version === null || $listing->version === $version)) {
                return $listing->filePath;
            }
        }

        throw new \Exception("Package '{$handle}'" . ($version ? " version {$version}" : '') . ' was not found in the Local Repository.');
    }

    private function getRepositoryPath(): string
    {
        $path = Craft::getAlias('@storage') . '/site7-studio/marketplace-repo';
        FileHelper::createDirectory($path);
        return $path;
    }

    private function readListing(string $zipPath): MarketplaceListing
    {
        $tempDir = Craft::getAlias('@storage') . '/runtime/site7-studio/repo-scan/' . uniqid('', true);

        try {
            PackageArchiveHelper::extractZip($zipPath, $tempDir, ['bundle-manifest.json']);
            $data = json_decode((string)file_get_contents($tempDir . '/bundle-manifest.json'), true) ?: [];
        } finally {
            if (is_dir($tempDir)) {
                FileHelper::removeDirectory($tempDir);
            }
        }

        $rootHandle = $data['rootHandle'] ?? null;
        if (!$rootHandle) {
            throw new \Exception('bundle-manifest.json is missing a rootHandle.');
        }

        $rootEntry = null;
        foreach ($data['packages'] ?? [] as $entry) {
            if (($entry['handle'] ?? null) === $rootHandle) {
                $rootEntry = $entry;
                break;
            }
        }

        return new MarketplaceListing([
            'handle' => $rootHandle,
            'type' => $data['rootType'] ?? ($rootEntry['type'] ?? null),
            'version' => $rootEntry['version'] ?? '0.0.0',
            'checksum' => $rootEntry['checksum'] ?? null,
            'filePath' => $zipPath,
            'fileName' => basename($zipPath),
            'size' => filesize($zipPath) ?: 0,
        ]);
    }
}
