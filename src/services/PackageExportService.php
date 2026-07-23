<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use site7\studio\events\PackageExportedEvent;
use site7\studio\models\marketplace\PackageBundleManifest;
use site7\studio\records\PackageRecord;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\Site7Studio;

/**
 * Exports an installed/available package as a distributable .s7pkg archive.
 *
 * A .s7pkg is a plain zip containing:
 *   - bundle-manifest.json          (PackageBundleManifest - describes what's inside)
 *   - packages/<handle>/...         (one full copy of each bundled package's own
 *                                     directory, exactly as PackageReader/
 *                                     PackageAuthoringService already read/write it -
 *                                     manifest.json plus whatever type-specific files
 *                                     it has: fields.yaml, matrix.yaml, template.twig,
 *                                     preview/, resources/, README.md, etc.)
 *
 * Since Patterns/Templates/Starter Kits only ever reference sibling packages
 * by handle (never duplicate their content - see PackageManifest's docblock),
 * an export is only self-contained if its full dependency closure is bundled
 * alongside it - which is the default here.
 */
class PackageExportService extends Component
{
    /**
     * Exports $handle (and, by default, everything it `requires`) to a new
     * .s7pkg file under storage/site7-studio/exports/, and returns its path.
     *
     * @throws \Exception if the package or one of its dependencies can't be found on disk.
     */
    public function exportPackage(string $handle, bool $includeDependencies = true): string
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $root = $packageManager->getPackageByHandle($handle);
        if (!$root) {
            throw new \Exception("Package '{$handle}' was not found.");
        }

        $closure = $includeDependencies
            ? $this->resolveDependencyClosure($handle)
            : [$handle => $root];

        $exportDir = Craft::getAlias('@storage') . '/site7-studio/exports';
        FileHelper::createDirectory($exportDir);

        $bundleEntries = [];
        $packagePaths = [];
        foreach ($closure as $entryHandle => $record) {
            $path = $packageManager->getPackagePath($entryHandle);
            if (!$path) {
                throw new \Exception("Package directory for '{$entryHandle}' could not be located on disk.");
            }
            $packagePaths[$entryHandle] = $path;
            $bundleEntries[] = [
                'handle' => $entryHandle,
                'type' => $record->type,
                'version' => $record->version,
                'checksum' => PackageArchiveHelper::computeDirectoryChecksum($path),
            ];
        }

        $bundle = new PackageBundleManifest([
            'schemaVersion' => PackageBundleManifest::SUPPORTED_SCHEMA_VERSION,
            'generatedAt' => (new \DateTime())->format(DATE_ATOM),
            'rootHandle' => $handle,
            'rootType' => $root->type,
            'craftVersion' => Craft::$app->getVersion(),
            'site7Version' => $this->getPluginVersion(),
            'packages' => $bundleEntries,
        ]);

        $filename = $handle . '-' . $root->version . '-' . date('YmdHis') . '.s7pkg';
        $zipPath = $exportDir . '/' . $filename;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Could not create archive at {$zipPath}.");
        }

        $zip->addFromString(
            'bundle-manifest.json',
            json_encode($bundle->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        foreach ($packagePaths as $entryHandle => $path) {
            PackageArchiveHelper::addDirectoryToZip($zip, $path, 'packages/' . $entryHandle);
        }

        $zip->close();

        $this->recordExportedVersions($closure, $bundleEntries);

        Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new PackageExportedEvent([
            'handle' => $handle,
            'path' => $zipPath,
            'bundledHandles' => array_keys($closure),
        ]));

        return $zipPath;
    }

    /**
     * Walks a package's `requires` graph (and, for Starter Kits, its
     * `pages[].templateHandle` references) to collect every package that
     * must be bundled alongside it for the export to be self-contained.
     * The root handle is always included, first.
     *
     * @return PackageRecord[] keyed by handle, root first
     * @throws \Exception if a required handle can't be resolved to an installed/available package.
     */
    public function resolveDependencyClosure(string $rootHandle): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $closure = [];
        $queue = [$rootHandle];

        while (!empty($queue)) {
            $currentHandle = array_shift($queue);
            if (isset($closure[$currentHandle])) {
                continue;
            }

            $record = $packageManager->getPackageByHandle($currentHandle);
            if (!$record) {
                throw new \Exception("Required package '{$currentHandle}' was not found; cannot resolve its dependency graph.");
            }
            $closure[$currentHandle] = $record;

            $manifest = $record->getManifest();
            if (!$manifest) {
                continue;
            }

            $requiredHandles = [];
            switch ($record->type) {
                case 'pattern':
                    $requiredHandles = $manifest->requires['sections'] ?? [];
                    break;
                case 'template':
                    $requiredHandles = array_merge(
                        $manifest->requires['patterns'] ?? [],
                        $manifest->requires['sections'] ?? []
                    );
                    break;
                case 'starter-kit':
                    $requiredHandles = $manifest->requires['templates'] ?? [];
                    foreach ($manifest->pages as $page) {
                        if (!empty($page['templateHandle'])) {
                            $requiredHandles[] = $page['templateHandle'];
                        }
                    }
                    break;
            }

            foreach (array_unique($requiredHandles) as $requiredHandle) {
                if (!isset($closure[$requiredHandle])) {
                    $queue[] = $requiredHandle;
                }
            }
        }

        return $closure;
    }

    /**
     * @param PackageRecord[] $closure keyed by handle
     * @param array $bundleEntries [{handle, type, version, checksum}]
     */
    private function recordExportedVersions(array $closure, array $bundleEntries): void
    {
        $marketplace = Site7Studio::getInstance()->marketplace;
        $checksumsByHandle = [];
        foreach ($bundleEntries as $entry) {
            $checksumsByHandle[$entry['handle']] = $entry['checksum'];
        }

        foreach ($closure as $handle => $record) {
            $marketplace->recordVersion($record, $checksumsByHandle[$handle] ?? null);
            $marketplace->syncDependencyRecords($record);
        }
    }

    private function getPluginVersion(): string
    {
        $plugin = Site7Studio::getInstance();
        $info = Craft::$app->getPlugins()->getPluginInfo($plugin->id);
        return $info['version'] ?? '1.0.0';
    }
}
