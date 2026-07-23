<?php

namespace site7\studio\interfaces;

use site7\studio\models\commerce\LicenseInfo;

/**
 * Contract for license management, backed by Commerce24 today. Swappable
 * for a different licensing backend without touching CommerceController or
 * any template - they depend on this interface, not on LicenseService.
 */
interface LicenseProviderInterface
{
    /** Returns the current (possibly cached) license state. Never throws - an unreachable Commerce24 yields a LicenseInfo with status 'unknown'. */
    public function getLicense(): LicenseInfo;

    /** Activates a license key against this machine/domain. */
    public function activate(string $licenseKey): LicenseInfo;

    /** Deactivates the currently activated license on this machine/domain. */
    public function deactivate(): bool;

    /** Re-fetches the license from Commerce24, bypassing the cache. */
    public function refresh(): LicenseInfo;

    /**
     * Validates the current license is still active. This result is cached - see FeatureGateService.
     * Named validateLicense(), not validate() - craft\base\Component (which
     * every service in this codebase extends) is itself a Model subclass and
     * already declares a validate($attributeNames, $clearErrors) with an
     * incompatible signature.
     */
    public function validateLicense(): bool;

    /** Transfers the current activation to a new license key. */
    public function transfer(string $newLicenseKey): LicenseInfo;
}
