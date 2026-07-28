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
        if (!$this->client->isConfigured()) {
            return [];
        }

        try {
            $data = $this->client->request('GET', '/marketplace/catalog');
        } catch (CommerceApiException $e) {
            Craft::warning('Could not list the Commerce24 Repository catalog: ' . $e->getMessage(), 'site7-studio');
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

        try {
            $binary = $this->client->request('GET', "/marketplace/download/{$handle}" . ($version ? "?version={$version}" : ''));
        } catch (CommerceApiException $e) {
            throw new \Exception("Could not download '{$handle}' from the Commerce24 Repository: " . $e->getMessage(), 0, $e);
        }

        // Commerce24 is expected to return the archive's bytes base64-encoded
        // inside a JSON envelope (like every other endpoint this client
        // calls) rather than a raw binary stream, to keep CommerceClient's
        // request() -> decoded-JSON contract uniform across every endpoint.
        if (empty($binary['contentsBase64'])) {
            throw new \Exception("Commerce24 did not return archive contents for '{$handle}'.");
        }

        file_put_contents($destination, base64_decode($binary['contentsBase64']));

        return $destination;
    }
}
