<?php

namespace site7\studio\repositories\marketplace;

use site7\studio\interfaces\PackagePublishTargetInterface;
use site7\studio\models\commerce\CommerceApiException;
use site7\studio\models\marketplace\PackageBundleManifest;
use site7\studio\services\commerce\CommerceClient;
use site7\studio\Site7Studio;

/**
 * Architecture preparation only, same stance as Commerce24MarketplaceRepository
 * (the install-side equivalent this mirrors) - not wired into any UI by
 * default. When Commerce24 exposes a real publish endpoint, register this
 * with RepositoryManagerService::registerTarget() the same way
 * Commerce24MarketplaceRepository would be registered with
 * MarketplaceService::registerRepository() once configured.
 */
class Commerce24PublishTarget implements PackagePublishTargetInterface
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
    public function supportsPublish(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @inheritdoc
     */
    public function publishPackage(string $s7pkgPath, PackageBundleManifest $bundle, array $metadata = []): string
    {
        if (!$this->supportsPublish()) {
            throw new \Exception('Commerce24 is not configured.');
        }

        try {
            // Matches CommerceClient's uniform decoded-JSON-response contract
            // (see Commerce24MarketplaceRepository::fetchPackage()) - the
            // archive's bytes travel base64-encoded inside the JSON body,
            // not as a raw upload stream.
            $response = $this->client->request('POST', '/marketplace/publish', [
                'json' => [
                    'handle' => $bundle->rootHandle,
                    'version' => $bundle->getRootEntry()['version'] ?? null,
                    'metadata' => $metadata,
                    'contentsBase64' => base64_encode((string)file_get_contents($s7pkgPath)),
                ],
            ]);
        } catch (CommerceApiException $e) {
            throw new \Exception('Could not publish to Commerce24: ' . $e->getMessage(), 0, $e);
        }

        return $response['listingUrl'] ?? $response['id'] ?? $bundle->rootHandle;
    }
}
