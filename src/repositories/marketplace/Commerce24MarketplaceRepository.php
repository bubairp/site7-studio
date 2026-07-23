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
 * Architecture preparation only - see the Marketplace Preparation section of
 * Phase 12: this repository is not yet wired into any UI. It exists to prove
 * out (and reserve) the plug-in point the existing Marketplace Foundation's
 * MarketplaceRepositoryInterface was already built for: alongside
 * LocalMarketplaceRepository (a folder on this server), a Commerce24-backed
 * repository can register itself with MarketplaceService the exact same way,
 * without any change to MarketplaceService, PackageImportService,
 * PackageManagerService, or the Marketplace tabs' templates/controller.
 *
 * When Commerce24 is configured, register it once - typically alongside the
 * other commerce services' bootstrapping - with:
 *
 *   Site7Studio::getInstance()->marketplace->registerRepository(new Commerce24MarketplaceRepository());
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
