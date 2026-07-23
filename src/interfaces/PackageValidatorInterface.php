<?php

namespace site7\studio\interfaces;

use site7\studio\models\publishing\PublishReadinessResult;

/**
 * Publish-readiness validation - distinct from (and never modifying)
 * site7\studio\services\engine\PackageValidator, the Package Engine's own
 * frozen validator that only gates install/discovery. A package can be
 * perfectly installable (passes the engine's check) while still failing
 * this one (missing a preview image, README, required metadata, etc.) -
 * this is what the Publish wizard's Validation/Quality-score steps use.
 */
interface PackageValidatorInterface
{
    /**
     * Named validatePackage(), not validate() - craft\base\Component (which
     * every service in this codebase extends) is itself a Model subclass and
     * already declares a validate($attributeNames, $clearErrors) with an
     * incompatible signature (see LicenseService::validateLicense() for the
     * same fix applied previously in the Commerce & Licensing Platform).
     */
    public function validatePackage(string $handle): PublishReadinessResult;
}
