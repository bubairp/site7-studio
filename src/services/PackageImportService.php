<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use site7\studio\events\PackageImportedEvent;
use site7\studio\models\marketplace\PackageBundleManifest;
use site7\studio\models\marketplace\PackageValidationResult;
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
    public function validatePackage(string $s7pkgPath): PackageValidationResult
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

            $existing = $packageManager->getPackageByHandle($handle);
            if ($existing) {
                $existingPath = $packageManager->getPackagePath($handle);
                $existingChecksum = $existingPath ? PackageArchiveHelper::computeDirectoryChecksum($existingPath) : null;
                if ($existingChecksum === $actualChecksum) {
                    $result->alreadyInstalled[] = $handle;
                } else {
                    $result->conflicts[] = $handle;
                }
            } else {
                $result->newPackages[] = $handle;
            }
        }

        $result->valid = empty($result->errors);
        return $result;
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
                if (is_dir($target)) {
                    FileHelper::removeDirectory($target);
                }
                FileHelper::copyDirectory($source, $target);
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
            }
        }

        if ($autoInstall) {
            try {
                if (!$packageManager->installPackage($validation->bundle->rootHandle)) {
                    throw new \Exception('installPackage() reported failure.');
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
