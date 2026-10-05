<?php

namespace site7\studio\repositories\marketplace;

use Craft;
use craft\helpers\FileHelper;
use site7\studio\interfaces\MarketplaceRepositoryInterface;
use site7\studio\models\commerce\CommerceApiException;
use site7\studio\models\marketplace\MarketplaceListing;
use site7\studio\services\commerce\CommerceClient;
use site7\studio\Site7Studio;

/**
 * The Commerce24-backed marketplace repository - proves out the plug-in
 * point MarketplaceRepositoryInterface was built for: alongside
 * LocalMarketplaceRepository (a folder on this server), this reads
 * Commerce24's own catalog, without any change to MarketplaceService,
 * PackageImportService, PackageManagerService, or the Marketplace tabs'
 * templates/controller.
 *
 * Auto-registered by MarketplaceService::init() alongside
 * LocalMarketplaceRepository, so it's live (not merely reserved) as soon as
 * Commerce24 is configured - listAvailablePackages() just returns []
 * otherwise, the same "degrade instead of gate" pattern every other
 * commerce service uses (see CommerceClient::isConfigured()).
 *
 * listAvailablePackages() downloads each entitled package on demand into a
 * local cache directory and returns it as an ordinary MarketplaceListing,
 * so everything downstream (validation, checksum verification, import)
 * behaves exactly as it does for a Local Repository file.
 */
class Commerce24MarketplaceRepository implements MarketplaceRepositoryInterface
{
    public CommerceClient $client;

    /**
     * Why the last listAvailablePackages() came back empty without a catalog
     * (not configured, or the request failed); null when it reached
     * Commerce24. The Repository tab shows it, so an empty list isn't
     * mistaken for an empty catalog.
     */
    public ?string $unavailableReason = null;

    public function __construct(?CommerceClient $client = null)
    {
        $this->client = $client ?? Site7Studio::getInstance()->commerceClient;
    }

    /**
     * @inheritdoc
     */
    public function getHandle(): string
    {
        return 'commerce24';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Commerce24 Repository';
    }

    /**
     * @inheritdoc
     */
    public function listAvailablePackages(): array
    {
        $this->unavailableReason = null;
        if (!$this->client->isConfigured()) {
            $this->unavailableReason = 'Commerce24 isn\'t connected. Set it up on the Commerce tab of Settings.';
            return [];
        }

        try {
            $data = $this->client->request('GET', '/marketplace/catalog');
        } catch (CommerceApiException $e) {
            Craft::warning('Could not list the Commerce24 Repository catalog: ' . $e->getMessage(), 'site7-studio');
            $this->unavailableReason = $e->getMessage();
            return [];
        }

        $listings = [];
        foreach ($data['packages'] ?? [] as $entry) {
            $listings[] = new MarketplaceListing([
                'handle' => $entry['handle'] ?? null,
                'type' => $entry['type'] ?? null,
                'version' => $entry['version'] ?? '0.0.0',
                'checksum' => $entry['checksum'] ?? null,
                'filePath' => '',
                'fileName' => ($entry['handle'] ?? 'package') . '.s7pkg',
                'size' => (int)($entry['size'] ?? 0),
            ]);
        }

        return $listings;
    }

    /**
     * @inheritdoc
     */
    public function fetchPackage(string $handle, ?string $version = null): string
    {
        if (!$this->client->isConfigured()) {
            throw new \Exception('Commerce24 is not configured.');
        }

        $cacheDir = Craft::getAlias('@storage') . '/site7-studio/commerce24-cache';
        FileHelper::createDirectory($cacheDir);
        $destination = $cacheDir . '/' . $handle . ($version ? "-{$version}" : '') . '.s7pkg';

        // download(), not request(): request() caches GET responses, which
        // put whole archives into Craft's cache and could serve a stale one.
        try {
            $this->client->download("/marketplace/download/{$handle}" . ($version ? "?version={$version}" : ''), $destination);
        } catch (CommerceApiException $e) {
            throw new \Exception("Could not download '{$handle}' from the Commerce24 Repository: " . $e->getMessage(), 0, $e);
        }

        return $destination;
    }
}
