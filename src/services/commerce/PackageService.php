<?php

namespace site7\studio\services\commerce;

use Craft;
use craft\base\Component;
use site7\studio\events\commerce\PackageInstalledEvent;
use site7\studio\events\commerce\PackageRemovedEvent;
use site7\studio\events\commerce\PackageUpdatedEvent;
use site7\studio\interfaces\CommerceClientInterface;
use site7\studio\interfaces\PackageProviderInterface;
use site7\studio\models\commerce\CommerceApiException;
use site7\studio\models\commerce\PlanInfo;
use site7\studio\Site7Studio;

/**
 * Commerce24's view of packages - purchases and entitlements - layered on
 * top of (never replacing) the Package Engine's PackageManagerService. See
 * PackageProviderInterface's docblock.
 *
 * Registered as `commercePackages` (not `packageManager`, which remains the
 * Package Engine's own service) to keep "what's installed" and "what's
 * entitled" clearly separate.
 */
class PackageService extends Component implements PackageProviderInterface
{
    private const CACHE_KEY = 'site7-studio.commerce24.entitlements';
    private const PENDING_DELETIONS_CACHE_KEY = 'site7-studio.commerce24.pending-deletions';

    /**
     * How long a package stays disabled-but-installed after a plan change
     * drops it, before it's eligible for removal. Gives the customer a
     * window to upgrade back and get it re-enabled without losing anything.
     */
    public const GRACE_PERIOD_DAYS = 14;

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
     * @inheritdoc
     */
    public function getPurchasedPackages(): array
    {
        return $this->getEntitlements()['purchased'] ?? [];
    }

    /**
     * @inheritdoc
     */
    public function getFreePackages(): array
    {
        return $this->getEntitlements()['free'] ?? [];
    }

    /**
     * @inheritdoc
     */
    public function getPremiumPackages(): array
    {
        return $this->getEntitlements()['premium'] ?? [];
    }

    /**
     * @inheritdoc
     */
    public function isEntitled(string $handle): bool
    {
        $entitlements = $this->getEntitlements();
        return in_array($handle, $entitlements['purchased'] ?? [], true)
            || in_array($handle, $entitlements['free'] ?? [], true);
    }

    /**
     * Whether $handle should stay enabled under $plan - entitled outright
     * (purchased/free), or included in $plan itself. Anything installed and
     * enabled that fails this check is a leftover from a higher plan the
     * account no longer has.
     */
    public function isCurrentlyAllowed(string $handle, PlanInfo $plan): bool
    {
        return $this->isEntitled($handle) || in_array($handle, $plan->includedPackages, true);
    }

    /**
     * Whether $handle can be installed/enabled right now - the gate
     * syncEntitlements() itself can't provide, since that only reacts to a
     * plan change already having happened. Without this, a user could
     * downgrade (disabling e.g. Pricing/Gallery), then simply go back to
     * Library and click Install/Enable on them again with nothing stopping
     * it. A handle Commerce24 doesn't catalog at all (never listed in any
     * plan's includedPackages, never purchased/free - a package the
     * developer authored locally) is never restricted; only handles
     * syncEntitlements() would also act on are gated here.
     */
    public function canInstallOrEnable(string $handle): bool
    {
        if (!$this->client->isConfigured()) {
            return true;
        }
        if (!in_array($handle, $this->getAllCommerceManagedHandles(), true)) {
            return true;
        }

        $plan = Site7Studio::getInstance()->plan->getCurrentPlan();
        return $plan !== null && $this->isCurrentlyAllowed($handle, $plan);
    }

    /**
     * Reconciles installed packages against $plan (the plan now in effect,
     * after an upgrade/downgrade actually applied): any enabled package no
     * longer covered by the account (not purchased, not free, not included
     * in this plan) gets disabled - never deleted outright. Deletion always
     * requires a separate, explicit action (see getPendingDeletions()/
     * deletePendingPackage()) once the grace period has passed, since it's
     * irreversible and this reconciliation runs unattended.
     *
     * Also auto-re-enables the reverse case: a package that's disabled and
     * back in an allowed plan/purchase, but only if $pending already has an
     * entry for it - i.e. this exact mechanism was what disabled it. That's
     * the only reliable signal available; a package disabled by the site
     * owner for unrelated reasons (before or after a downgrade) was never
     * added to $pending and is deliberately left alone, since silently
     * re-enabling content someone chose to turn off would be worse than
     * requiring a manual Enable click.
     *
     * @return array{disabled: string[], reEnabled: string[]}
     */
    public function syncEntitlements(PlanInfo $plan): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $pending = $this->getPendingDeletions();
        $disabled = [];
        $reEnabled = [];
        $managedHandles = $this->getAllCommerceManagedHandles();

        foreach ($packageManager->getAllPackages() as $record) {
            // Only packages Commerce24 actually catalogs (in some plan's
            // includedPackages, or purchased/free) are ever touched here -
            // a package the developer authored locally and never listed
            // with Commerce24 at all isn't "premium," it's just theirs, and
            // plan changes have no opinion on it.
            if (!in_array($record->handle, $managedHandles, true)) {
                continue;
            }

            if ($this->isCurrentlyAllowed($record->handle, $plan)) {
                if (isset($pending[$record->handle])) {
                    unset($pending[$record->handle]);
                    if ($record->status === 'disabled') {
                        $packageManager->enablePackage($record->handle);
                        $reEnabled[] = $record->handle;
                    }
                }
                continue;
            }

            if ($record->status !== 'enabled') {
                continue;
            }

            $packageManager->disablePackage($record->handle);
            $pending[$record->handle] = date('Y-m-d', strtotime('+' . self::GRACE_PERIOD_DAYS . ' days'));
            $disabled[] = $record->handle;
        }

        $this->savePendingDeletions($pending);

        return ['disabled' => $disabled, 'reEnabled' => $reEnabled];
    }

    /**
     * Every package handle Commerce24 actually knows about - listed in some
     * plan's includedPackages, or in this account's purchased/free lists.
     * syncEntitlements() only ever acts on handles in this set.
     */
    private function getAllCommerceManagedHandles(): array
    {
        $handles = array_merge($this->getPurchasedPackages(), $this->getFreePackages());
        foreach (Site7Studio::getInstance()->plan->getAllPlans() as $planInfo) {
            $handles = array_merge($handles, $planInfo->includedPackages);
        }
        return array_unique($handles);
    }

    /**
     * Packages disabled by a past syncEntitlements() call, keyed by handle,
     * with the date they become eligible for removal.
     *
     * @return array<string, string>
     */
    public function getPendingDeletions(): array
    {
        $data = Craft::$app->getCache()->get(self::PENDING_DELETIONS_CACHE_KEY);
        return is_array($data) ? $data : [];
    }

    /**
     * Whether $handle is past its grace period and eligible for removal.
     */
    public function isEligibleForRemoval(string $handle): bool
    {
        $pending = $this->getPendingDeletions();
        return isset($pending[$handle]) && strtotime($pending[$handle]) <= time();
    }

    /**
     * Permanently deletes a package that syncEntitlements() flagged and whose
     * grace period has passed. Requires an explicit call (a confirmed button
     * click in CommerceController) - never invoked automatically, since
     * PackageManagerService::deletePackage() is irreversible.
     *
     * @throws \Exception if $handle isn't actually past its grace period.
     */
    public function deletePendingPackage(string $handle): bool
    {
        if (!$this->isEligibleForRemoval($handle)) {
            throw new \Exception("'{$handle}' is not past its grace period yet.");
        }

        $result = Site7Studio::getInstance()->packageManager->deletePackage($handle);
        if ($result) {
            $pending = $this->getPendingDeletions();
            unset($pending[$handle]);
            $this->savePendingDeletions($pending);
        }

        return $result;
    }

    private function savePendingDeletions(array $pending): void
    {
        Craft::$app->getCache()->set(self::PENDING_DELETIONS_CACHE_KEY, $pending, 0);
    }

    /**
     * Installs an entitled package by handle through the existing Package
     * Engine, then dispatches the commerce-domain PackageInstalledEvent.
     * Rejects non-entitled handles rather than silently installing them -
     * business logic (entitlement checks) lives here, not in a controller.
     *
     * @throws \Exception if $handle isn't entitled, or the Package Engine install fails.
     */
    public function installEntitled(string $handle): bool
    {
        if (!$this->isEntitled($handle)) {
            throw new \Exception("'{$handle}' is not included in your current plan or purchases.");
        }

        $packageManager = Site7Studio::getInstance()->packageManager;
        if (!$packageManager->installPackage($handle)) {
            return false;
        }
        $packageManager->enablePackage($handle);

        Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new PackageInstalledEvent(['handle' => $handle]));

        return true;
    }

    /**
     * Removes a package through the existing Package Engine, then dispatches
     * the commerce-domain PackageRemovedEvent.
     */
    public function removePackage(string $handle): bool
    {
        $result = Site7Studio::getInstance()->packageManager->removePackage($handle);
        if ($result) {
            Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new PackageRemovedEvent(['handle' => $handle]));
        }
        return $result;
    }

    /**
     * Updates an installed package to a newer version via the existing
     * Marketplace update flow, then dispatches the commerce-domain
     * PackageUpdatedEvent.
     */
    public function updatePackage(string $handle): array
    {
        $record = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        $fromVersion = $record?->version;

        $summary = Site7Studio::getInstance()->marketplace->updatePackage($handle);

        $updated = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new PackageUpdatedEvent([
            'handle' => $handle,
            'fromVersion' => $fromVersion,
            'toVersion' => $updated?->version,
        ]));

        return $summary;
    }

    private function getEntitlements(): array
    {
        if (!$this->client->isConfigured()) {
            return ['purchased' => [], 'free' => [], 'premium' => []];
        }

        try {
            return Site7Studio::getInstance()->cache->getOrSet(
                self::CACHE_KEY,
                fn() => $this->client->request('GET', '/packages/entitlements'),
                (int)Site7Studio::getInstance()->getSettings()->commerceCacheDuration,
                ['commerce24', 'commerce24-entitlements']
            );
        } catch (CommerceApiException $e) {
            Craft::warning('Could not fetch package entitlements from Commerce24: ' . $e->getMessage(), 'site7-studio');
            return ['purchased' => [], 'free' => [], 'premium' => []];
        }
    }
}
