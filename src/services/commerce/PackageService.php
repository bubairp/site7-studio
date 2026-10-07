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
use site7\studio\records\PackageRecord;
use site7\studio\services\library\LibraryDistribution;
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
     *
     * Checks the current plan's includedPackages too, not just purchased/free
     * - PackageProviderInterface documents this as "free, included in the
     * current plan, or purchased," and installEntitled() (the only way the
     * Commerce & Licensing "Available to Install" UI can actually install
     * something) relies on this covering plan-included packages, not only
     * ones bought outright.
     */
    public function isEntitled(string $handle): bool
    {
        $entitlements = $this->getEntitlements();
        // Commerce24's lists include what a bought or plan package requires
        // (a pack's pages and their blocks, Commerce24 Entitlements::closure()).
        if (in_array($handle, $entitlements['purchased'] ?? [], true)
            || in_array($handle, $entitlements['free'] ?? [], true)
            || in_array($handle, $entitlements['premium'] ?? [], true)) {
            return true;
        }

        $plan = Site7Studio::getInstance()->plan->getCurrentPlan();
        return $plan !== null && in_array($handle, $plan->includedPackages, true);
    }

    /**
     * Whether $handle should stay enabled under $plan - entitled outright
     * (purchased/free/plan-included per isEntitled(), which already checks
     * the *current* plan), or included in this specific $plan. The two only
     * ever differ when $plan isn't the current plan; every existing caller
     * (syncEntitlements(), canInstallOrEnable()) always passes the current
     * plan, so this is effectively isEntitled() plus a defensive fallback
     * for that case, not a second independent check.
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
     *
     * Paid packages (manifest pricingType other than "free") are gated even
     * when Commerce24 is unconfigured/unreachable or doesn't list the handle -
     * fails closed, so clearing the API settings or copying a .s7pkg onto
     * another site doesn't unlock them. Packages created on this site
     * (creatorId set) are never gated, so an authoring site can always
     * install its own packages.
     */
    public function canInstallOrEnable(string $handle): bool
    {
        $record = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        if ($record !== null && $record->creatorId !== null) {
            return true;
        }

        $isPaid = $this->isPaidPackage($record);
        if (!$this->client->isConfigured()) {
            return !$isPaid;
        }
        if (!$isPaid && !in_array($handle, $this->getAllCommerceManagedHandles(), true)) {
            return true;
        }

        // isEntitled() covers free, purchased and current-plan packages, so a
        // free Library package installs without a plan too (it used to need one).
        return $this->isEntitled($handle);
    }

    /**
     * Whether a package is sold rather than free, per its pricingType
     * (free/premium/private/enterprise - see the Publish wizard's Metadata
     * step). A pricingType verified by a signature at import wins over
     * manifest.json on disk, which can be edited afterwards; otherwise the
     * manifest decides, and a missing/unreadable one counts as free.
     */
    public function isPaidPackage(?PackageRecord $record): bool
    {
        if ($record !== null && $record->verifiedPricingType !== null) {
            return self::isPaidPricingType($record->verifiedPricingType);
        }

        return self::isPaidPricingType($record?->getManifest()?->pricingType);
    }

    public static function isPaidPricingType(?string $pricingType): bool
    {
        return $pricingType !== null && $pricingType !== '' && $pricingType !== 'free';
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
     * back in an allowed plan/purchase, but only if it already has an
     * entitlementRemovableOn date set - i.e. this exact mechanism was what
     * disabled it. That's the only reliable signal available; a package
     * disabled by the site owner for unrelated reasons (before or after a
     * downgrade) was never given that date and is deliberately left alone,
     * since silently re-enabling content someone chose to turn off would be
     * worse than requiring a manual Enable click.
     *
     * @return array{disabled: string[], reEnabled: string[]}
     */
    public function syncEntitlements(PlanInfo $plan): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $disabled = [];
        $reEnabled = [];
        $managedHandles = $this->getAllCommerceManagedHandles();

        foreach ($packageManager->getAllPackages() as $record) {
            // Only packages Commerce24 actually catalogs (in some plan's
            // includedPackages, or purchased/free) are ever touched here -
            // a package the developer authored locally and never listed
            // with Commerce24 at all isn't "premium," it's just theirs, and
            // plan changes have no opinion on it.
            // A paid package is managed even when no plan names it: a pack's
            // pages come with the pack, so they go when the pack does.
            if (!in_array($record->handle, $managedHandles, true)
                && ($record->creatorId !== null || !$this->isPaidPackage($record))) {
                continue;
            }

            if ($this->isCurrentlyAllowed($record->handle, $plan)) {
                if ($record->entitlementRemovableOn !== null) {
                    PackageRecord::updateAll(['entitlementRemovableOn' => null], ['handle' => $record->handle]);
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
            $removableOn = date('Y-m-d H:i:s', strtotime('+' . self::GRACE_PERIOD_DAYS . ' days'));
            PackageRecord::updateAll(['entitlementRemovableOn' => $removableOn], ['handle' => $record->handle]);
            $disabled[] = $record->handle;
        }

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
     * with the date they become eligible for removal - read straight off
     * PackageRecord::$entitlementRemovableOn (see its migration's docblock
     * for why this isn't a cache entry: it needs to survive an admin
     * clearing caches for an unrelated reason).
     *
     * @return array<string, string>
     */
    public function getPendingDeletions(): array
    {
        $rows = PackageRecord::find()
            ->select(['handle', 'entitlementRemovableOn'])
            ->where(['not', ['entitlementRemovableOn' => null]])
            ->asArray()
            ->all();

        $pending = [];
        foreach ($rows as $row) {
            $pending[$row['handle']] = date('Y-m-d', strtotime($row['entitlementRemovableOn']));
        }

        return $pending;
    }

    /**
     * Whether $handle is past its grace period and eligible for removal.
     */
    public function isEligibleForRemoval(string $handle): bool
    {
        $record = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        return $record !== null
            && $record->entitlementRemovableOn !== null
            && strtotime($record->entitlementRemovableOn) <= time();
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
        // A Theme or Starter Kit set up this site: it stays (disabled, so no
        // updates), and what it brought keeps not counting as extra packages.
        if (Site7Studio::getInstance()->packageManager->setsUpTheSite($handle)) {
            throw new \Exception("'{$handle}' set up this site, so it stays installed. It gets no updates until your plan includes it again.");
        }

        // No separate "unset from pending" step needed - the row (and its
        // entitlementRemovableOn column) is gone along with everything else
        // deletePackage() removes.
        return Site7Studio::getInstance()->packageManager->deletePackage($handle);
    }

    /**
     * Installs an entitled package by handle through the existing Package
     * Engine, then dispatches the commerce-domain PackageInstalledEvent.
     * Rejects non-entitled handles rather than silently installing them -
     * business logic (entitlement checks) lives here, not in a controller.
     *
     * A package that isn't in this site's Library (never downloaded, or
     * deleted since) is downloaded from Commerce24 first, with whatever it
     * requires that's missing too.
     *
     * @throws \Exception if $handle isn't entitled, can't be downloaded, or the Package Engine install fails.
     */
    public function installEntitled(string $handle): bool
    {
        if (!$this->isEntitled($handle)) {
            throw new \Exception("'{$handle}' is not included in your current plan or purchases.");
        }
        $this->assertWithinPackageLimit($handle);

        $packageManager = Site7Studio::getInstance()->packageManager;
        $distribution = new LibraryDistribution();
        $entry = $distribution->catalog()[$handle] ?? [];
        $isPack = $packageManager->isPack($handle) || !empty($entry['metadata']['library']['pack']);
        if (!$isPack && ($packageManager->setsUpTheSite($handle) || in_array($entry['type'] ?? null, ['theme', 'starter-kit'], true))) {
            throw new \Exception("'{$handle}' sets up the whole site: install it from Site7 Studio → Install.");
        }
        if (!$packageManager->getPackagePath($handle) && ($errors = $distribution->downloadWithRequirements($handle))) {
            throw new \Exception('Download from Commerce24 failed: ' . implode(' ', $errors));
        }
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

    /**
     * The packages this site's Starter Kit brought: the kit, its Theme, its
     * pages and their blocks (from the local manifests' requires). They
     * don't count toward the plan's package limit.
     *
     * @return array<string, true>
     */
    public function kitPackageHandles(): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $records = $packageManager->getAllPackages();
        $local = [];
        foreach ($records as $record) {
            $local[$record->handle] = ['requires' => (array)($record->getManifest()?->requires ?? [])];
        }
        $handles = [];
        foreach ($records as $record) {
            // A page pack's pages count as the pack while it's enabled; a
            // pack the plan no longer includes is disabled and its pages count.
            $isKit = $record->type === 'starter-kit' && $packageManager->setsUpTheSite($record->handle)
                && \site7\studio\services\PackageManagerService::hasSetUpTheSite($record);
            $isPack = $record->type === 'starter-kit' && $record->status === 'enabled' && $packageManager->isPack($record->handle);
            if ($isKit || $isPack) {
                foreach (LibraryDistribution::closure($record->handle, $local)['handles'] as $handle) {
                    $handles[$handle] = true;
                }
            }
        }

        return $handles;
    }

    /**
     * Packages installed beyond the Starter Kit, against the plan's
     * packageLimit (null = unlimited). Not counted: what the kit brought,
     * pages this site saved as its own Templates, and packages a plan change
     * disabled (they can't be used until the plan includes them again).
     *
     * @return array{used: int, limit: int|null}
     */
    public function extraPackageUsage(): array
    {
        $kit = $this->kitPackageHandles();
        $used = 0;
        foreach (Site7Studio::getInstance()->packageManager->getAllPackages() as $record) {
            if ($record->status !== 'available' && !isset($kit[$record->handle]) && $record->creatorId === null
                && $record->entitlementRemovableOn === null) {
                $used++;
            }
        }

        return ['used' => $used, 'limit' => Site7Studio::getInstance()->plan->getCurrentPlan()?->packageLimit];
    }

    /**
     * Refuses a new install that would take the site past its plan's package
     * limit. Reinstalling something already installed, the kit's own
     * packages and this site's own Templates never count.
     *
     * @throws \Exception
     */
    public function assertWithinPackageLimit(string $handle): void
    {
        if (!$this->client->isConfigured()) {
            return;
        }
        $record = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        if (($record && ($record->status !== 'available' || $record->creatorId !== null)) || isset($this->kitPackageHandles()[$handle])) {
            return;
        }
        ['used' => $used, 'limit' => $limit] = $this->extraPackageUsage();
        if ($limit !== null && $used >= $limit) {
            throw new \Exception($limit === 0
                ? "Your plan doesn't include packages beyond your Starter Kit. Upgrade your plan to add '{$handle}'."
                : "Your plan includes {$limit} " . ($limit === 1 ? 'package' : 'packages') . " beyond your Starter Kit, and all are in use. Remove one, or upgrade your plan to add '{$handle}'.");
        }
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
