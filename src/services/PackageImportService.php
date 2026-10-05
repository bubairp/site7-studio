<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use site7\studio\events\PackageImportedEvent;
use site7\studio\models\marketplace\PackageBundleManifest;
use site7\studio\models\marketplace\PackageValidationResult;
use site7\studio\models\marketplace\SignatureVerification;
use site7\studio\services\commerce\PackageService;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\Site7Studio;

/**
 * Validates and installs a .s7pkg archive, backing the Import tab's
 * Select -> Validate -> Preview -> Install flow.
 *
 * validatePackage() does the Select/Validate/Preview steps in one pass: it
 * extracts the archive to a scratch directory under storage/runtime and
 * returns a PackageValidationResult describing exactly what's inside and
 * what installing it would do (new packages, already-installed packages that
 * would be skipped, and handle conflicts). Nothing is written into
 * @packages or the database at this point.
 *
 * importPackage() performs the Install step: given a *valid*
 * PackageValidationResult, it copies each bundled package's directory into
 * @packages/<handle> (skipping already-installed/unconfirmed-conflict
 * handles), lets PackageManagerService::discoverPackages() register them,
 * persists their dependency/version history, and - unless the caller opts
 * out - installs and enables the archive's root package (which cascades
 * into its own requires, exactly like installing any other package does).
 */
class PackageImportService extends Component
{
    /**
     * Extracts and validates a .s7pkg file without installing anything.
     * Always returns a result object, even for a completely invalid file -
     * check ->valid (or ->errors) before offering Install.
     */
    public function validatePackage(string $s7pkgPath, bool $requireSignature = false): PackageValidationResult
    {
        $result = new PackageValidationResult(['sourcePath' => $s7pkgPath]);

        if (!is_file($s7pkgPath)) {
            $result->errors[] = 'The uploaded file could not be found.';
            return $result;
        }

        $tempDir = Craft::getAlias('@storage') . '/runtime/site7-studio/import/' . StringHelper::UUID();
        try {
            PackageArchiveHelper::extractZip($s7pkgPath, $tempDir);
        } catch (\Throwable $e) {
            $result->errors[] = 'Could not open this file as a .s7pkg archive: ' . $e->getMessage();
            return $result;
        }
        $result->tempDir = $tempDir;

        $bundleManifestPath = $tempDir . '/bundle-manifest.json';
        if (!file_exists($bundleManifestPath)) {
            $result->errors[] = 'Not a valid Site7 package: missing bundle-manifest.json.';
            return $result;
        }

        $data = json_decode((string)file_get_contents($bundleManifestPath), true);
        if (!is_array($data)) {
            $result->errors[] = 'bundle-manifest.json is not valid JSON.';
            return $result;
        }

        $bundle = new PackageBundleManifest($data);
        if (!$bundle->validate()) {
            $result->errors[] = 'bundle-manifest.json failed validation: ' . implode(' ', $bundle->getFirstErrors());
            return $result;
        }
        $result->bundle = $bundle;

        if ($bundle->schemaVersion !== PackageBundleManifest::SUPPORTED_SCHEMA_VERSION) {
            $result->warnings[] = "This archive's bundle schema version ({$bundle->schemaVersion}) differs from the version this installation supports (" . PackageBundleManifest::SUPPORTED_SCHEMA_VERSION . '). It may not import correctly.';
        }

        if ($bundle->craftVersion !== '') {
            $currentMajor = explode('.', Craft::$app->getVersion())[0];
            $bundleMajor = explode('.', $bundle->craftVersion)[0];
            if ($bundleMajor !== $currentMajor) {
                $result->warnings[] = "This package was exported from Craft {$bundle->craftVersion}; this site runs Craft " . Craft::$app->getVersion() . '.';
            }
        }

        if (empty($bundle->packages)) {
            $result->errors[] = 'This archive does not contain any packages.';
            return $result;
        }

        if (!empty($bundle->requiredSharedResources)) {
            $sharedResourceRegistry = Site7Studio::getInstance()->sharedResourceRegistry;
            foreach ($bundle->requiredSharedResources as $sharedHandle) {
                if (!$sharedResourceRegistry->getByHandle($sharedHandle)) {
                    $result->warnings[] = "This archive requires the Shared Resource '{$sharedHandle}', which does not exist on this site. Install/register it before enabling the imported package.";
                }
            }
        }

        $packageManager = Site7Studio::getInstance()->packageManager;

        foreach ($bundle->packages as $entry) {
            $handle = $entry['handle'] ?? null;
            $type = $entry['type'] ?? null;
            $expectedChecksum = $entry['checksum'] ?? null;

            if (!$handle || !$type) {
                $result->errors[] = 'bundle-manifest.json contains an incomplete package entry.';
                continue;
            }

            $extractedPath = $tempDir . '/packages/' . $handle;
            if (!is_dir($extractedPath) || !file_exists($extractedPath . '/manifest.json')) {
                $result->errors[] = "Bundled package '{$handle}' is missing its files or manifest.json.";
                continue;
            }

            $actualChecksum = PackageArchiveHelper::computeDirectoryChecksum($extractedPath);
            if ($expectedChecksum && $actualChecksum !== $expectedChecksum) {
                $result->errors[] = "Checksum mismatch for package '{$handle}' - the archive may be corrupted or altered.";
                continue;
            }

            $packageManifest = json_decode((string)file_get_contents($extractedPath . '/manifest.json'), true);
            $result->pricingTypes[$handle] = (string)($packageManifest['pricingType'] ?? 'free');

            $existing = $packageManager->getPackageByHandle($handle);
            $existingPath = $existing ? $packageManager->getPackagePath($handle) : null;
            // A row whose folder is gone (an interrupted import) has no local
            // files to protect: the archive restores them.
            if ($existing && $existingPath) {
                $existingChecksum = PackageArchiveHelper::computeDirectoryChecksum($existingPath);
                if ($existingChecksum === $actualChecksum) {
                    $result->alreadyInstalled[] = $handle;
                } else {
                    $result->conflicts[] = $handle;
                }
            } else {
                $result->newPackages[] = $handle;
            }
        }

        $this->checkSignature($result, $s7pkgPath, $requireSignature);

        $result->valid = empty($result->errors);
        return $result;
    }

    /**
     * Stores what the archive's signature vouched for, so the licence gate
     * (commerce PackageService::isPaidPackage()) uses the signed pricingType
     * rather than manifest.json, which can be edited after import.
     */
    private function recordSignature(\site7\studio\records\PackageRecord $record, PackageValidationResult $validation, string $handle): void
    {
        $verified = $validation->signature?->isVerified() ?? false;
        $record->signatureStatus = $validation->signature?->status;
        $record->signatureKeyId = $verified ? $validation->signature->keyId : null;
        $record->verifiedPricingType = $verified ? ($validation->pricingTypes[$handle] ?? 'free') : null;
        $record->save(false);
    }

    /**
     * Signature rules (docs/47_PACKAGE_SIGNING.md): a bad signature is
     * always refused; an unsigned archive is refused when the caller
     * requires a signature (Commerce24 downloads) or when it contains a
     * paid package, and otherwise imports with a warning. The signature
     * covers bundle-manifest.json, whose per-package checksums were matched
     * above, so a verified signature vouches for every bundled file.
     */
    private function checkSignature(PackageValidationResult $result, string $s7pkgPath, bool $requireSignature): void
    {
        /** @var \site7\studio\services\publishing\Ed25519PackageSigner $signer */
        $signer = Site7Studio::getInstance()->packageSigner;
        $result->signature = $signer->verifyArchive($s7pkgPath);

        if ($result->signature->status === SignatureVerification::INVALID) {
            $result->errors[] = 'Package signature is not valid: ' . $result->signature->message;
            return;
        }
        if ($result->signature->isVerified()) {
            // Checksums are only compared when present, and the signature
            // only vouches for files through them.
            foreach ($result->bundle?->packages ?? [] as $entry) {
                if (empty($entry['checksum'])) {
                    $result->errors[] = "Signed archive has no checksum for package '" . ($entry['handle'] ?? '?') . "', so its files aren't covered by the signature.";
                }
            }
            return;
        }

        if ($requireSignature) {
            $result->errors[] = 'This package is not signed. Packages from Commerce24 must be signed.';
            return;
        }

        $paid = array_keys(array_filter($result->pricingTypes, [PackageService::class, 'isPaidPricingType']));
        if ($paid !== []) {
            $result->errors[] = 'This archive is not signed but contains paid packages (' . implode(', ', $paid) . '). Paid packages must come signed from Commerce24.';
            return;
        }

        $result->warnings[] = 'This archive is not signed. It contains only free packages, so it can still be imported.';
    }

    /**
     * Installs a validated archive. $options:
     *   - overwriteConflicts (bool, default false): replace locally-installed
     *     packages that share a handle with a bundled one but differ in content.
     *     Without this, conflicting handles are left untouched and reported as skipped.
     *   - install (bool, default true): install (and cascade-install) the
     *     archive's root package once its files are in place.
     *   - enable (bool, default true): also enable the root package (only
     *     applies if install is true).
     *
     * @return array{installed: string[], skipped: string[], errors: string[]}
     * @throws \Exception if $validation isn't valid.
     */
    public function importPackage(PackageValidationResult $validation, array $options = []): array
    {
        if (!$validation->valid || !$validation->bundle) {
            throw new \Exception('Cannot import a package that failed validation.');
        }

        $overwriteConflicts = (bool)($options['overwriteConflicts'] ?? false);
        $autoInstall = (bool)($options['install'] ?? true);
        $autoEnable = (bool)($options['enable'] ?? true);

        $basePath = Craft::getAlias('@packages');
        FileHelper::createDirectory($basePath);

        $summary = ['installed' => [], 'skipped' => [], 'errors' => []];

        foreach ($validation->bundle->packages as $entry) {
            $handle = $entry['handle'];

            if (in_array($handle, $validation->alreadyInstalled, true)) {
                $summary['skipped'][] = $handle;
                continue;
            }
            if (in_array($handle, $validation->conflicts, true) && !$overwriteConflicts) {
                $summary['skipped'][] = $handle;
                continue;
            }

            $source = $validation->tempDir . '/packages/' . $handle;
            $target = rtrim($basePath, '/') . '/' . $handle;

            try {
                PackageArchiveHelper::replaceDirectory($source, $target);
            } catch (\Throwable $e) {
                $summary['errors'][] = "{$handle}: " . $e->getMessage();
            }
        }

        $packageManager = Site7Studio::getInstance()->packageManager;
        $packageManager->discoverPackages();

        $marketplace = Site7Studio::getInstance()->marketplace;
        foreach ($validation->bundle->packages as $entry) {
            $record = $packageManager->getPackageByHandle($entry['handle']);
            if ($record) {
                $marketplace->recordVersion($record, $entry['checksum'] ?? null);
                $marketplace->syncDependencyRecords($record);
                // A skipped handle kept its own files, which this archive's signature doesn't cover.
                if (!in_array($entry['handle'], $summary['skipped'], true)) {
                    $this->recordSignature($record, $validation, $entry['handle']);
                }
            }
        }

        if ($autoInstall) {
            try {
                if (!$packageManager->installPackage($validation->bundle->rootHandle)) {
                    throw new \Exception(implode(' ', $packageManager->getLastInstallWarnings()) ?: 'installPackage() reported failure.');
                }
                if ($autoEnable) {
                    $packageManager->enablePackage($validation->bundle->rootHandle);
                }
                $summary['installed'][] = $validation->bundle->rootHandle;
            } catch (\Throwable $e) {
                $summary['errors'][] = $validation->bundle->rootHandle . ': ' . $e->getMessage();
            }
        }

        if (is_dir($validation->tempDir)) {
            FileHelper::removeDirectory($validation->tempDir);
        }

        Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new PackageImportedEvent([
            'rootHandle' => $validation->bundle->rootHandle,
            'summary' => $summary,
        ]));

        return $summary;
    }
}
