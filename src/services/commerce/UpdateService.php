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
            'packages' => $this->getEntitledPackageUpdates(),
            'security' => $this->fetchCommerceUpdates('/updates/security'),
            'dependencies' => $this->fetchCommerceUpdates('/updates/dependencies'),
        ];

        if (!empty($updates['plugin']) || !empty($updates['packages']) || !empty($updates['security']) || !empty($updates['dependencies'])) {
            Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new UpdatesAvailableEvent(['updates' => $updates]));
        }

        return $updates;
    }

    /**
     * Every available package update, per MarketplaceService::checkForUpdates(),
     * narrowed to packages Commerce24 actually entitles this account to - "for
     * entitled packages" is this tab's whole point, per the Commerce &
     * Licensing spec; a locally-authored package Commerce24 has never heard
     * of isn't "entitled" or "not entitled," it's just not its concern at
     * all, so it's excluded here even though Marketplace's own Updates tab
     * (a different audience - local package management, not commerce) still
     * shows it.
     *
     * Offline (Commerce24 not configured), entitlement has no meaning to
     * check against, so everything is left in rather than hidden - local
     * package management must keep working per the Offline Mode requirement.
     *
     * Public (not just an internal helper of checkUpdates()) so callers that
     * only need the package-update count/list - Overview's stat card, e.g. -
     * can get it without also triggering checkUpdates()'s three uncached
     * Commerce24 endpoint calls for plugin/security/dependency updates.
     *
     * @return array<string, \site7\studio\models\marketplace\MarketplaceListing>
     */
    public function getEntitledPackageUpdates(): array
    {
        $all = Site7Studio::getInstance()->marketplace->checkForUpdates();
        if (!$this->client->isConfigured()) {
            return $all;
        }

        $commercePackages = Site7Studio::getInstance()->commercePackages;
        return array_filter($all, fn(string $handle) => $commercePackages->isEntitled($handle), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Applies every available (entitled) package update via the commerce
     * PackageService - mirrors checkUpdates()'s own entitlement filter, so
     * "Update All" never touches more than what's actually shown as
     * available on this tab.
     *
     * @return array{updated: string[], errors: string[]}
     */
    public function updateAll(): array
    {
        $packageUpdates = $this->getEntitledPackageUpdates();
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
