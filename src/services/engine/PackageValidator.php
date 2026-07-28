<?php

namespace site7\studio\services\engine;

use craft\base\Component;
use site7\studio\models\packages\Package;

/**
 * PackageValidator enforces Site7 package rules beyond simple manifest schema validation.
 */
class PackageValidator extends Component
{
    /**
     * Validates a Package and returns an array of error messages.
     * Empty array means valid.
     *
     * @param Package $package
     * @return array
     */
    public function validatePackage(Package $package): array
    {
        $errors = [];

        // Validate that manifest is present and valid (usually already done by PackageReader)
        if (!$package->manifest || !$package->manifest->validate()) {
            $errors[] = "Manifest is invalid or missing.";
        }

        // Validate basic directory structure
        $path = rtrim($package->path, '/\\');

        // Architecture handbook: "Packages missing required assets should fail validation."
        // Required assets checked here: Documentation (README.md).
        $readmePath = $path . DIRECTORY_SEPARATOR . 'README.md';
        if (!file_exists($readmePath)) {
            $errors[] = "Missing required documentation (README.md).";
        }

        // Dependency validation could go here, but full dependency resolution is in Milestone 4.3 or 4.1 says:
        // "Does NOT implement: ... Dependency resolution"
        // So we will skip deep dependency checking here.

        return $errors;
    }

    /**
     * Helper to throw an exception if invalid.
     *
     * @param Package $package
     * @throws \Exception
     */
    public function ensureValid(Package $package): void
    {
        $errors = $this->validatePackage($package);
        if (!empty($errors)) {
            throw new \Exception("Package Validation Failed: " . implode(" ", $errors));
        }
    }
}
