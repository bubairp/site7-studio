<?php

namespace site7\studio\services\library;

use Craft;
use craft\base\Component;
use site7\studio\models\commerce\CommerceApiException;
use site7\studio\models\marketplace\PackageBundleManifest;
use site7\studio\repositories\marketplace\Commerce24MarketplaceRepository;
use site7\studio\repositories\marketplace\Commerce24PublishTarget;
use site7\studio\services\import\SectionSchemaService;
use site7\studio\services\PackageExportService;
use site7\studio\services\PackageImportService;
use site7\studio\services\starterkit\KitBuilder;
use site7\studio\services\template\TemplateBuilder;
use site7\studio\services\theme\ThemeBuilder;
use site7\studio\Site7Studio;

/**
 * Library packages through Commerce24 (docs/52_LIBRARY_DISTRIBUTION.md).
 *
 * Author site: publish() sends each Library package - Section (format v2),
 * Theme, Template (format v2), Library Starter Kit - to Commerce24 as its
 * own archive, with catalog metadata (name, pricingType, requires...), so
 * a site downloads only what it needs.
 *
 * Customer site: check() resolves a Starter Kit's packages from the catalog
 * (kit -> Theme + Templates -> Sections) and their entitlements; download()
 * fetches the missing ones - every archive must be signed by Commerce24 -
 * into this site's Library, without installing them.
 */
class LibraryDistribution extends Component
{
    /** Library kinds that are distributed, with the file marking their current format. */
    private const KINDS = [
        'section' => SectionSchemaService::FILE,
        'theme' => ThemeBuilder::META_FILE,
        'template' => TemplateBuilder::META_FILE,
        'starter-kit' => KitBuilder::META_FILE,
    ];

    /** requires keys that point at other Library packages */
    private const REQUIRES_KEYS = ['themes', 'templates', 'sections', 'patterns'];

    // ------------------------------------------------------------- author side

    /**
     * Handles of the Library packages that are distributed: every package of
     * a KINDS type carrying that kind's current-format file.
     *
     * @return string[]
     */
    public function libraryHandles(): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $packageManager->discoverPackages();
        $handles = [];
        foreach ($packageManager->getAllPackages() as $record) {
            $path = $packageManager->getPackagePath($record->handle);
            if ($path && isset(self::KINDS[$record->type]) && self::isCurrentFormat($record->type, $path)) {
                $handles[] = $record->handle;
            }
        }
        sort($handles);

        return $handles;
    }

    /**
     * Publishes what changed, through the plugin's one versioning path
     * (CLAUDE.md invariant 3): a package whose directory differs from its
     * newest recorded version gets a new version from
     * VersionManagerService::createVersion() (bumped from the highest version
     * ever recorded, so it never goes backwards; archived and recorded in
     * site7_package_versions); an unchanged one is published only if that
     * version hasn't reached Commerce24 yet. A package with no version
     * history goes out at its current version (exported and recorded the
     * same way).
     *
     * @param string[] $handles packages to consider (default: libraryHandles())
     * @param string $bump patch|minor|major
     * @param bool $force publish the newest version again even if Commerce24 has it
     * @return array{published: string[], unchanged: string[], errors: string[]}
     */
    public function publish(array $handles = [], ?callable $log = null, string $bump = 'patch', bool $force = false, ?string $releaseNotes = null): array
    {
        $log ??= fn(string $line) => null;
        $plugin = Site7Studio::getInstance();
        $target = new Commerce24PublishTarget();
        if (!$target->supportsPublish()) {
            return ['published' => [], 'unchanged' => [], 'errors' => ['Commerce24 is not configured (Settings > Commerce).']];
        }

        $result = ['published' => [], 'unchanged' => [], 'errors' => []];
        foreach ($handles ?: $this->libraryHandles() as $handle) {
            try {
                $record = $plugin->packageManager->getPackageByHandle($handle) ?? throw new \Exception('not in the Library');
                $dir = (string)$plugin->packageManager->getPackagePath($handle);
                $checksum = \site7\studio\services\support\PackageArchiveHelper::computeDirectoryChecksum($dir);
                $latest = self::latestVersion($record->id);

                if ($latest !== null && $latest->checksum === $checksum && is_file((string)$latest->archivePath)) {
                    if (!$force && self::isPublished($record->id, $latest->version)) {
                        $result['unchanged'][] = $handle;
                        continue;
                    }
                    [$version, $path] = [$latest->version, $latest->archivePath];
                } elseif ($latest !== null && $latest->checksum !== $checksum) {
                    $versionRecord = $plugin->versionManager->createVersion($handle, $bump, $releaseNotes);
                    [$version, $path] = [$versionRecord->version, $versionRecord->archivePath];
                } else {
                    // Each package alone: its requirements are separate listings,
                    // so a site downloads each package once, only if it needs it.
                    $path = (new PackageExportService())->exportPackage($handle, false);
                    $version = $plugin->packageManager->getPackageByHandle($handle)->version;
                }

                $bundle = $this->bundleManifest($path);
                $target->publishPackage($path, $bundle, $this->metadata($handle) + array_filter(['releaseNotes' => $releaseNotes]));
                $plugin->publishHistory->recordPublish($handle, 'commerce24', $version, 'published', $releaseNotes);
                $result['published'][] = $handle;
                $log(sprintf('Published %s %s (%s)', $handle, $version, Craft::$app->getFormatter()->asShortSize(filesize($path))));
            } catch (\Throwable $e) {
                $result['errors'][] = "{$handle}: {$e->getMessage()}";
                $log("FAILED {$handle}: {$e->getMessage()}");
            }
        }

        return $result;
    }

    /** The highest recorded version of a package. */
    private static function latestVersion(int $packageId): ?\site7\studio\records\PackageVersionRecord
    {
        $latest = null;
        foreach (\site7\studio\records\PackageVersionRecord::find()->where(['packageId' => $packageId])->all() as $version) {
            if ($latest === null || version_compare($version->version, $latest->version, '>')) {
                $latest = $version;
            }
        }

        return $latest;
    }

    private static function isPublished(int $packageId, string $version): bool
    {
        return \site7\studio\records\PackagePublicationRecord::find()
            ->where(['packageId' => $packageId, 'repositoryHandle' => 'commerce24', 'version' => $version, 'status' => 'published'])
            ->exists();
    }

    /**
     * Catalog metadata of a Library package: what Commerce24 shows and what
     * a site needs to resolve a kit before downloading anything.
     */
    public function metadata(string $handle): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($handle) ?? throw new \Exception("Package '{$handle}' was not found.");
        $path = (string)$packageManager->getPackagePath($handle);
        $manifest = json_decode((string)file_get_contents("{$path}/manifest.json"), true) ?: [];
        $library = null;
        if (in_array($record->type, ['theme', 'template', 'starter-kit'], true)) {
            $library = json_decode((string)@file_get_contents("{$path}/" . self::KINDS[$record->type]), true);
            unset($library['envKeys']);
        }

        return array_filter([
            'name' => $manifest['name'] ?? $record->name,
            'description' => $manifest['description'] ?? '',
            'category' => $manifest['category'] ?? null,
            'pricingType' => $manifest['pricingType'] ?? 'free',
            'requires' => self::libraryRequires($manifest['requires'] ?? []),
            'formatVersion' => $record->type === 'section' ? SectionSchemaService::FORMAT_VERSION : ($library['formatVersion'] ?? 1),
            'library' => $library,
        ], fn($value) => $value !== null);
    }

    /** Sets a Library package's price type in its manifest.json; builders keep it on rebuild. */
    public function setPricing(string $handle, string $pricingType): void
    {
        $path = Site7Studio::getInstance()->packageManager->getPackagePath($handle) ?? throw new \Exception("Package '{$handle}' was not found.");
        $manifest = json_decode((string)file_get_contents("{$path}/manifest.json"), true);
        $manifest['pricingType'] = $pricingType;
        file_put_contents("{$path}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    // ----------------------------------------------------------- customer side

    /**
     * Commerce24's catalog keyed by handle; [] when not configured or unreachable.
     *
     * @return array<string, array>
     */
    public function catalog(): array
    {
        $client = Site7Studio::getInstance()->commerceClient;
        if (!$client->isConfigured()) {
            return [];
        }
        try {
            $data = $client->request('GET', '/marketplace/catalog');
        } catch (CommerceApiException $e) {
            Craft::warning('Could not read the Commerce24 catalog: ' . $e->getMessage(), 'site7-studio');
            return [];
        }

        $catalog = [];
        foreach ($data['packages'] ?? [] as $entry) {
            if (!empty($entry['handle'])) {
                $catalog[$entry['handle']] = $entry;
            }
        }

        return $catalog;
    }

    /**
     * Library Starter Kits Commerce24 offers that aren't in this site's Library.
     *
     * @return array[] catalog entries
     */
    public function remoteKits(): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;

        return array_values(array_filter(
            $this->catalog(),
            fn($entry) => ($entry['type'] ?? null) === 'starter-kit'
                && (int)($entry['metadata']['formatVersion'] ?? 0) === KitBuilder::FORMAT_VERSION
                && !$packageManager->getPackagePath($entry['handle'])
        ));
    }

    /**
     * $handle and everything it requires, transitively, in catalog order
     * of discovery; handles missing from the catalog are reported apart.
     *
     * @return array{handles: string[], missing: string[]}
     */
    public static function closure(string $handle, array $catalog): array
    {
        $seen = [];
        $missing = [];
        $queue = [$handle];
        while ($queue) {
            $current = array_shift($queue);
            if (isset($seen[$current]) || in_array($current, $missing, true)) {
                continue;
            }
            if (!isset($catalog[$current])) {
                $missing[] = $current;
                continue;
            }
            $seen[$current] = true;
            foreach (self::libraryRequires($catalog[$current]['requires'] ?? []) as $required) {
                $queue = array_merge($queue, $required);
            }
        }

        return ['handles' => array_keys($seen), 'missing' => $missing];
    }

    /**
     * Whether a kit from Commerce24 can be downloaded and installed here.
     *
     * @return array{errors: string[], warnings: string[], kit: array|null, download: string[], downloadSize: int}
     */
    public function check(string $kitHandle): array
    {
        $result = ['errors' => [], 'warnings' => [], 'kit' => null, 'download' => [], 'downloadSize' => 0];
        $catalog = $this->catalog();
        $kit = $catalog[$kitHandle] ?? null;
        if (($kit['type'] ?? null) !== 'starter-kit') {
            $result['errors'][] = "'{$kitHandle}' is not a Starter Kit in the Commerce24 catalog.";
            return $result;
        }
        $result['kit'] = $kit;

        $closure = self::closure($kitHandle, $catalog);
        foreach ($closure['missing'] as $missing) {
            $result['errors'][] = "The kit needs '{$missing}', which Commerce24 doesn't offer.";
        }
        $packageManager = Site7Studio::getInstance()->packageManager;
        foreach ($closure['handles'] as $handle) {
            $entry = $catalog[$handle];
            if (empty($entry['entitled']) && array_key_exists('entitled', $entry)) {
                $result['errors'][] = "'" . ($entry['name'] ?? $handle) . "' is a paid package that isn't in your plan or purchases.";
            }
            if (!$packageManager->getPackagePath($handle)) {
                $result['download'][] = $handle;
                $result['downloadSize'] += (int)($entry['size'] ?? 0);
            }
        }

        $craftVersion = $kit['metadata']['library']['craftVersion'] ?? null;
        if ($craftVersion && $craftVersion !== Craft::$app->getVersion()) {
            $result['errors'][] = "This kit was built on Craft {$craftVersion}; this site runs Craft " . Craft::$app->getVersion() . '.';
        }

        return $result;
    }

    /**
     * Downloads packages into this site's Library (not installed). Each
     * archive must carry a valid Commerce24 signature.
     *
     * @param string[] $handles
     * @return string[] errors
     */
    public function download(array $handles, ?callable $log = null): array
    {
        $log ??= fn(string $line) => null;
        $repository = new Commerce24MarketplaceRepository();
        $importer = new PackageImportService();
        $packageManager = Site7Studio::getInstance()->packageManager;
        $errors = [];
        $count = count($handles);
        foreach (array_values($handles) as $i => $handle) {
            if ($packageManager->getPackagePath($handle)) {
                continue;
            }
            try {
                $path = $repository->fetchPackage($handle);
                $validation = $importer->validatePackage($path, true);
                if (!$validation->valid) {
                    throw new \Exception(implode(' ', $validation->errors));
                }
                $summary = $importer->importPackage($validation, ['install' => false]);
                if ($summary['errors']) {
                    throw new \Exception(implode(' ', $summary['errors']));
                }
                $log(sprintf('Downloaded %d/%d %s (%s, signature %s)', $i + 1, $count, $handle, Craft::$app->getFormatter()->asShortSize(filesize($path)), $validation->signature?->keyId ?? '?'));
                @unlink($path);
            } catch (\Throwable $e) {
                $errors[] = "{$handle}: {$e->getMessage()}";
                $log("FAILED {$handle}: {$e->getMessage()}");
                break;
            }
        }

        return $errors;
    }

    // ----------------------------------------------------------------- helpers

    public static function isCurrentFormat(string $type, string $path): bool
    {
        if (!isset(self::KINDS[$type]) || !is_file("{$path}/" . self::KINDS[$type])) {
            return false;
        }
        if ($type === 'section') {
            $schema = json_decode((string)file_get_contents("{$path}/" . SectionSchemaService::FILE), true);
            return (int)($schema['formatVersion'] ?? 0) === SectionSchemaService::FORMAT_VERSION;
        }

        return true;
    }

    /** @return array<string, string[]> the requires keys that point at Library packages */
    private static function libraryRequires(array $requires): array
    {
        return array_filter(
            array_map(fn($handles) => array_values((array)$handles), array_intersect_key($requires, array_flip(self::REQUIRES_KEYS))),
            fn($handles) => $handles !== []
        );
    }

    private function bundleManifest(string $s7pkgPath): PackageBundleManifest
    {
        $zip = new \ZipArchive();
        if ($zip->open($s7pkgPath) !== true) {
            throw new \Exception("Could not open {$s7pkgPath}.");
        }
        $data = json_decode((string)$zip->getFromName('bundle-manifest.json'), true) ?: [];
        $zip->close();

        return new PackageBundleManifest($data);
    }
}
