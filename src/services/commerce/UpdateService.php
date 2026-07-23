<?php

namespace site7\studio\services\commerce;

use Craft;
use craft\base\Component;
use site7\studio\events\commerce\UpdatesAvailableEvent;
use site7\studio\interfaces\CommerceClientInterface;
use site7\studio\models\commerce\CommerceApiException;
use site7\studio\Site7Studio;

/**
 * Aggregates every kind of "update available" this plugin knows about:
 * plugin updates and security/dependency advisories (from Commerce24), and
 * package updates (from the existing Marketplace Foundation's own
 * MarketplaceService::checkForUpdates(), which already compares installed
 * packages against every registered MarketplaceRepositoryInterface - this
 * doesn't duplicate that logic, it just includes its result).
 */
class UpdateService extends Component
{
    public CommerceClientInterface $client;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        if (!isset($this->client)) {
            $this->client = Site7Studio::getInstance()->commerceClient;
        }
    }

    /**
     * @return array{plugin: array, packages: array, security: array, dependencies: array}
     */
    public function checkUpdates(): array
    {
        $updates = [
            'plugin' => $this->fetchCommerceUpdates('/updates/plugin'),
            'packages' => Site7Studio::getInstance()->marketplace->checkForUpdates(),
            'security' => $this->fetchCommerceUpdates('/updates/security'),
            'dependencies' => $this->fetchCommerceUpdates('/updates/dependencies'),
        ];

        if (!empty($updates['plugin']) || !empty($updates['packages']) || !empty($updates['security']) || !empty($updates['dependencies'])) {
            Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new UpdatesAvailableEvent(['updates' => $updates]));
        }

        return $updates;
    }

    /**
     * Applies every available package update via the commerce PackageService.
     *
     * @return array{updated: string[], errors: string[]}
     */
    public function updateAll(): array
    {
        $packageUpdates = Site7Studio::getInstance()->marketplace->checkForUpdates();
        return $this->updateHandles(array_keys($packageUpdates));
    }

    /**
     * @param string[] $handles
     * @return array{updated: string[], errors: string[]}
     */
    public function updateSelected(array $handles): array
    {
        return $this->updateHandles($handles);
    }

    private function updateHandles(array $handles): array
    {
        $result = ['updated' => [], 'errors' => []];
        $packageService = new PackageService();

        foreach ($handles as $handle) {
            try {
                $packageService->updatePackage($handle);
                $result['updated'][] = $handle;
            } catch (\Throwable $e) {
                $result['errors'][] = "{$handle}: " . $e->getMessage();
            }
        }

        return $result;
    }

    private function fetchCommerceUpdates(string $endpoint): array
    {
        if (!$this->client->isConfigured()) {
            return [];
        }

        try {
            return $this->client->request('GET', $endpoint);
        } catch (CommerceApiException $e) {
            Craft::warning("Could not check {$endpoint} on Commerce24: " . $e->getMessage(), 'site7-studio');
            return [];
        }
    }
}
