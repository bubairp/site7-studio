<?php

namespace site7\studio\interfaces;

/**
 * Contract for Commerce24's view of packages - purchases and entitlements -
 * layered on top of (never replacing) the Package Engine's own
 * PackageManagerService, which remains the sole authority on what's actually
 * installed/enabled on disk. A commerce PackageService cross-references the
 * two: "is this locally-installed package one the customer actually paid
 * for, and is it a free/premium/purchased package."
 */
interface PackageProviderInterface
{
    /** Package handles this customer has purchased. */
    public function getPurchasedPackages(): array;

    /** Package handles that are free for every plan. */
    public function getFreePackages(): array;

    /** Package handles that require a purchase or a plan that includes them. */
    public function getPremiumPackages(): array;

    /** Whether $handle is available to this account (free, included in the current plan, or purchased). */
    public function isEntitled(string $handle): bool;
}
