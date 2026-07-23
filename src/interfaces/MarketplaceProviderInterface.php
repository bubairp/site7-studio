<?php

namespace site7\studio\interfaces;

/**
 * Contract for Commerce24's own marketplace catalog and entitlement data -
 * distinct from (and layered above) MarketplaceRepositoryInterface, which
 * this plugin's existing Marketplace Foundation already uses to describe a
 * *source of .s7pkg archive files* (Local Repository today, a future
 * Commerce24Repository implementing that same interface tomorrow).
 *
 * MarketplaceProviderInterface instead answers commerce questions - "what
 * packages does this plan/license entitle the site to" - which a future
 * Commerce24-aware bridge would consult before deciding whether to fetch
 * and hand a given package off to the existing PackageImportService.
 * Nothing in this milestone builds that bridge or its UI yet (see the
 * Marketplace Preparation section of Phase 12); this interface only
 * reserves the seam so it can be implemented later without changing
 * PackageManagerService, PackageImportService, or MarketplaceService.
 */
interface MarketplaceProviderInterface
{
    /**
     * Every package Commerce24 currently lists for this account, entitled or not.
     *
     * @return array<int, array{handle: string, type: string, name: string, price: ?string, entitled: bool}>
     */
    public function getCatalog(): array;

    /** Whether the current plan/license entitles this site to install $handle. */
    public function isEntitled(string $handle): bool;
}
