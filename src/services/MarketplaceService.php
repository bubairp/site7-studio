<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use site7\studio\interfaces\MarketplaceRepositoryInterface;
use site7\studio\records\PackageDependencyRecord;
use site7\studio\records\PackageRecord;
use site7\studio\records\PackageVersionRecord;
use site7\studio\repositories\marketplace\LocalMarketplaceRepository;
use site7\studio\Site7Studio;

/**
 * Orchestrates the Marketplace Foundation: the catalog of repositories
 * packages can be fetched from (today, only a Local Repository - a folder on
 * this server - but built around MarketplaceRepositoryInterface so remote/
 * private/company/official repositories can register alongside it later),
 * update checking, and the Package Manager actions (Reinstall/Repair) that
 * sit next to Export/Import in the Marketplace UI.
 *
 * This does not replace PackageManagerService - installing, enabling,
 * disabling and Craft-resource generation all still happen there. This
 * service is additive: it reads/writes the previously-dormant
 * site7_package_dependencies and site7_package_versions tables (present
 * since the original package-tables migration but never populated until
 * Package Distribution), and layers repository/update/reinstall/repair
 * behavior on top of the existing install lifecycle.
 */
class MarketplaceService extends Component
{
    /** @var MarketplaceRepositoryInterface[] keyed by handle */
    private array $repositories = [];

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        if (empty($this->repositories)) {
            $this->registerRepository(new LocalMarketplaceRepository());
        }
    }

    /**
     * Registers a repository. Future repository types (remote, private,
     * company, official Site7 Marketplace) call this the same way a Local
     * Repository does here, without any change to this service.
     */
    public function registerRepository(MarketplaceRepositoryInterface $repository): void
    {
        $this->repositories[$repository->getHandle()] = $repository;
    }

    /**
     * @return MarketplaceRepositoryInterface[] keyed by handle
     */
    public function getRepositories(): array
    {
        return $this->repositories;
    }

    /**
     * The combined catalog of every package every registered repository
     * currently has available.
     *
     * @return \site7\studio\models\marketplace\MarketplaceListing[]
     */
    public function getCatalog(): array
    {
        $listings = [];
        foreach ($this->repositories as $repository) {
            foreach ($repository->listAvailablePackages() as $listing) {
                $listings[] = $listing;
            }
        }
        return $listings;
    }

    /**
     * Compares every locally-installed package's version against the newest
     * version of the same handle found across all repositories.
     *
     * @return \site7\studio\models\marketplace\MarketplaceListing[] keyed by package handle - only handles with a newer version available
     */
    public function checkForUpdates(): array
    {
        $installed = Site7Studio::getInstance()->packageManager->getAllPackages();
        $catalog = $this->getCatalog();

        $updates = [];
        foreach ($installed as $record) {
            foreach ($catalog as $listing) {
                if ($listing->handle !== $record->handle) {
                    continue;
                }
                if (version_compare($listing->version, $record->version, '>')) {
                    if (!isset($updates[$record->handle]) || version_compare($listing->version, $updates[$record->handle]->version, '>')) {
                        $updates[$record->handle] = $listing;
                    }
                }
            }
        }
        return $updates;
    }

    /**
     * Fetches and imports the newest available version of an installed
     * package from whichever repository has it, overwriting the currently
     * installed copy and re-installing/re-enabling it.
     *
     * @throws \Exception if no update is available, or the update fails validation.
     */
    public function updatePackage(string $handle): array
    {
        $updates = $this->checkForUpdates();
        if (!isset($updates[$handle])) {
            throw new \Exception("No update is available for '{$handle}'.");
        }
        $listing = $updates[$handle];

        $importService = new PackageImportService();
        $validation = $importService->validatePackage($listing->filePath);
        if (!$validation->valid) {
            throw new \Exception('The available update failed validation: ' . implode(' ', $validation->errors));
        }

        return $importService->importPackage($validation, [
            'overwriteConflicts' => true,
            'install' => true,
            'enable' => true,
        ]);
    }

    /**
     * Removes and reinstalls a package's generated Craft resources from its
     * current files on disk, preserving its enable state. Useful when a
     * package's Craft resources (fields/entry types) have drifted from its
     * package files, without needing a full re-import.
     */
    public function reinstallPackage(string $handle): bool
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            return false;
        }

        $wasEnabled = $record->status === 'enabled';

        $packageManager->removePackage($handle);
        if (!$packageManager->installPackage($handle)) {
            return false;
        }
        if ($wasEnabled) {
            $packageManager->enablePackage($handle);
        }

        return true;
    }

    /**
     * Re-syncs a package's DB record from its files on disk and, for an
     * installed/enabled Section, regenerates any of its Craft resources
     * (fields/entry types) that may be missing - without touching content,
     * enable state, or Matrix linkage. Lighter-weight than Reinstall.
     */
    public function repairPackage(string $handle): bool
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $packageManager->discoverPackages();

        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            return false;
        }

        if ($record->type === 'section' && in_array($record->status, ['installed', 'enabled'], true)) {
            $path = $packageManager->getPackagePath($handle);
            if ($path) {
                Site7Studio::getInstance()->craftResourceGenerator->generateResources($path);
            }
        }

        return true;
    }

    /**
     * Persists a package's currently-resolved `requires` graph into
     * site7_package_dependencies (previously written by nothing - the
     * install-time cascade in PackageManagerService::installPackage() reads
     * straight from the manifest instead). Called after import/export so the
     * table reflects reality; replaces any rows already recorded for this
     * package to avoid duplicates on repeated imports.
     */
    public function syncDependencyRecords(PackageRecord $record): void
    {
        PackageDependencyRecord::deleteAll(['packageId' => $record->id]);

        $manifest = $record->getManifest();
        if (!$manifest) {
            return;
        }

        foreach ($manifest->requires as $dependencyType => $handles) {
            foreach ((array)$handles as $dependencyHandle) {
                if (!is_string($dependencyHandle) || $dependencyHandle === '') {
                    continue;
                }
                $dependency = new PackageDependencyRecord();
                $dependency->packageId = $record->id;
                $dependency->dependencyType = $dependencyType;
                $dependency->dependencyHandle = $dependencyHandle;
                $dependency->optional = false;
                $dependency->save();
            }
        }
    }

    /**
     * Records a version/checksum history entry into site7_package_versions
     * (previously written by nothing). One row per distinct version actually
     * exported or imported for a package; safe to call repeatedly for the
     * same version.
     */
    public function recordVersion(PackageRecord $record, ?string $checksum): void
    {
        $existing = PackageVersionRecord::find()
            ->where(['packageId' => $record->id, 'version' => $record->version])
            ->one();
        if ($existing) {
            return;
        }

        $version = new PackageVersionRecord();
        $version->packageId = $record->id;
        $version->version = $record->version;
        $version->releaseDate = (new \DateTime())->format('Y-m-d H:i:s');
        $version->checksum = $checksum;
        $version->save();
    }
}
