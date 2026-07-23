<?php

namespace site7\studio\interfaces;

use site7\studio\models\marketplace\PackageBundleManifest;

/**
 * Where a built .s7pkg can be published to - Local Repository today, a
 * future Private/Commerce24/Enterprise repository tomorrow, registered with
 * RepositoryManagerService exactly the way MarketplaceService::registerRepository()
 * already works for install-side sources.
 *
 * Named PackagePublishTargetInterface rather than the generic "RepositoryInterface"
 * on purpose: this codebase already has two other things a bare "Repository"
 * name would collide with in meaning - site7\studio\repositories\PackageRepository
 * (the Package Engine's DB persistence class) and
 * site7\studio\interfaces\MarketplaceRepositoryInterface (the existing
 * *install-side* / pull contract: list + fetch, no publish/push concept at
 * all). Deliberately a sibling interface, not an extension of
 * MarketplaceRepositoryInterface - a read-only future "official curated
 * marketplace, browse-only" source shouldn't be forced to fake publish
 * support just to implement one interface. A single class (e.g. a future
 * "Local Repository" implementation) can implement both where that's
 * genuinely true, as this phase's LocalPublishTarget does.
 */
interface PackagePublishTargetInterface
{
    public function getHandle(): string;

    public function getName(): string;

    /** Whether this target currently accepts publishes (e.g. false if a remote target isn't configured/connected yet). */
    public function supportsPublish(): bool;

    /**
     * Publishes a built .s7pkg. Returns whatever this target considers the
     * published listing's canonical identifier/URL (implementation-specific -
     * e.g. a local file path, or a remote listing URL/ID once a real
     * remote target exists), for PublishHistoryService to record.
     *
     * @param string $s7pkgPath Absolute path to the already-built archive.
     * @throws \Exception if publishing fails.
     */
    public function publishPackage(string $s7pkgPath, PackageBundleManifest $bundle, array $metadata = []): string;
}
