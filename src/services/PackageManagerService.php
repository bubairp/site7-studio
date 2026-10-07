<?php

namespace site7\studio\services;

use craft\base\Component;
use site7\studio\repositories\PackageRepository;
use site7\studio\services\engine\PackageDiscovery;
use site7\studio\registries\MemoryPackageRegistry;
use site7\studio\records\PackageRecord;
use site7\studio\records\PackageVersionRecord;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\services\synchronization\PackageUpdatePlanner;
use site7\studio\services\starterkit\KitInstaller;
use site7\studio\services\template\TemplateInstaller;
use site7\studio\Site7Studio;
use Craft;

/**
 * PackageManagerService manages the high-level interactions with packages.
 * It is responsible for orchestrating discovery and loading packages from the repository.
 */
class PackageManagerService extends Component
{
    /**
     * @var PackageRepository
     */
    public PackageRepository $repository;

    /**
     * @var PackageDiscovery
     */
    public PackageDiscovery $discovery;

    /**
     * Non-blocking warnings from the most recent installPackage() call (e.g.
     * a missing Shared Resource dependency) - surfaced by
     * PackageActionController as a CP notice alongside the install result.
     * @var string[]
     */
    private array $_lastInstallWarnings = [];

    /**
     * @return string[]
     */
    public function getLastInstallWarnings(): array
    {
        return $this->_lastInstallWarnings;
    }

    /**
     * Fields/Entry Types the most recent deletePackage() call deliberately
     * left in place because they're still used elsewhere (by real content or
     * another Section/field layout) - surfaced by PackageActionController as
     * a CP notice, so "deleted" doesn't silently mean "some of it wasn't."
     * @var string[]
     */
    private array $_lastDeleteWarnings = [];

    /**
     * @return string[]
     */
    public function getLastDeleteWarnings(): array
    {
        return $this->_lastDeleteWarnings;
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        if (!isset($this->repository)) {
            $this->repository = new PackageRepository();
        }
        if (!isset($this->discovery)) {
            $this->discovery = new PackageDiscovery();
            $this->discovery->registry = new MemoryPackageRegistry();
            $this->discovery->init();
        }
    }

    /**
     * Discovers all packages from configured sources and persists them to the repository.
     * This fulfills the requirement: PackageDiscovery -> PackageReader -> PackageValidator -> PackageRepository
     *
     * @return int Number of packages discovered and saved.
     */
    public function discoverPackages(): int
    {
        $pluginPath = Craft::getAlias('@site7/studio');
        $packagesPath = dirname($pluginPath) . '/packages'; // /plugins/site7-studio/packages

        $totalDiscovered = 0;

        if (is_dir($packagesPath)) {
            $totalDiscovered += $this->discovery->discoverFromPath($packagesPath);
        }

        // Save everything discovered in the registry into the DB repository
        $packages = $this->discovery->registry->getAllPackages();
        foreach ($packages as $package) {
            $this->repository->save($package);
        }

        // Clear registry to free memory
        $this->discovery->registry->clear();

        return $totalDiscovered;
    }

    /**
     * Returns all packages from the repository.
     * Discovers them first if none exist, or on every call (since we're in dev).
     *
     * @return PackageRecord[]
     */
    public function getAllPackages(): array
    {
        // For development, we'll sync packages on every load to ensure the DB matches the filesystem.
        $this->discoverPackages();
        return $this->repository->findAll();
    }

    /**
     * Gets a package by its handle from the repository.
     *
     * @param string $handle
     * @return PackageRecord|null
     */
    public function getPackageByHandle(string $handle): ?PackageRecord
    {
        return $this->repository->findByHandle($handle);
    }

    /**
     * Gets the absolute path to a package directory.
     *
     * @param string $handle
     * @return string|null
     */
    public function getPackagePath(string $handle): ?string
    {
        // Handles reach here from request params: never let one leave packages/.
        if ($handle === '' || preg_match('#[/\\\\]|\.\.#', $handle)) {
            return null;
        }
        $pluginPath = Craft::getAlias('@site7/studio');
        $basePath = dirname($pluginPath);
        $packagePath = $basePath . '/packages/' . $handle;
        return is_dir($packagePath) ? $packagePath : null;
    }

    /**
     * Installs a package.
     */
    public function installPackage(string $handle): bool
    {
        $this->_lastInstallWarnings = [];

        $record = $this->getPackageByHandle($handle);
        if (!$record) {
            return false;
        }

        if (!$this->isLicensed($handle)) {
            return false;
        }

        // Resolve Shared Resource dependencies (Phase 16's Dependency Engine)
        // ahead of the existing per-type cascade below - a genuinely new edge
        // (Section/any package -> Shared Resource) that cascade doesn't cover.
        // Never blocks install: a missing Shared Resource is warned about and
        // left for the developer to resolve from the Shared Resources Library
        // (Import/Create/Skip) - see DependencyResolverService's docblock.
        $manifest = $record->getManifest();
        $sharedResourceHandles = (array)($manifest->dependencies['sharedResources'] ?? []);
        if (!empty($sharedResourceHandles)) {
            $resolution = (new DependencyResolverService())->resolveSharedResources($sharedResourceHandles);
            foreach ($resolution['warnings'] as $warning) {
                $this->_lastInstallWarnings[] = $warning;
                Craft::warning($warning, __METHOD__);
            }
        }

        // If it is a template, verify and install its required Sections first.
        // Templates never generate their own Craft resources - installing one
        // cascades into its required Sections. A requires.patterns list left by
        // an older version is ignored: Patterns are no longer a package type.
        if ($record->type === 'template') {
            // Format v2 (docs/50): the page's content is installed below,
            // once its blocks are - check what it needs first.
            if (TemplateInstaller::isFormatV2($this->getPackagePath($handle))
                && ($errors = (new TemplateInstaller())->preflight($record))) {
                throw new \Exception(implode(' ', $errors));
            }
            $manifest = $record->getManifest();
            if ($manifest) {
                foreach (['sections' => 'section'] as $requiresKey => $requiredKind) {
                    foreach ($manifest->requires[$requiresKey] ?? [] as $requiredHandle) {
                        $requiredRecord = $this->getPackageByHandle($requiredHandle);

                        if (!$requiredRecord) {
                            $this->discoverPackages();
                            $requiredRecord = $this->getPackageByHandle($requiredHandle);
                        }

                        if ($requiredRecord) {
                            if ($requiredRecord->status !== 'enabled') {
                                if ($requiredRecord->status === 'available') {
                                    $this->installPackage($requiredHandle);
                                }
                                $this->enablePackage($requiredHandle);
                            }
                        } else {
                            throw new \Exception("Required {$requiredKind} package '{$requiredHandle}' was not found.");
                        }
                    }
                }
            }
        }

        // If it is a Starter Kit, verify and install its required Templates first -
        // same cascade as templates cascading into their sections above.
        // This only installs the Template packages themselves; it does not create
        // any pages - that's StarterKitInstallationService::installStarterKit()'s job,
        // triggered by the separate "Install Starter Kit" action once this package
        // (the Starter Kit's own library entry) is enabled.
        if ($record->type === 'starter-kit') {
            // Library Starter Kit (docs/51): its Templates install below;
            // its own content once they have. Same checks as a Template.
            if (KitInstaller::isFormatV2($this->getPackagePath($handle))
                && ($errors = (new TemplateInstaller())->preflight($record))) {
                throw new \Exception(implode(' ', $errors));
            }
            // Its sections first: its pages and their content go into them.
            if (KitInstaller::isFormatV2($this->getPackagePath($handle))) {
                (new \site7\studio\services\theme\ThemeInstaller())->addSections(KitInstaller::kitSections((string)$this->getPackagePath($handle)));
            }
            $manifest = $record->getManifest();
            if ($manifest) {
                foreach ($manifest->requires['templates'] ?? [] as $requiredHandle) {
                    $requiredRecord = $this->getPackageByHandle($requiredHandle);

                    if (!$requiredRecord) {
                        $this->discoverPackages();
                        $requiredRecord = $this->getPackageByHandle($requiredHandle);
                    }

                    if ($requiredRecord) {
                        if ($requiredRecord->status !== 'enabled') {
                            if ($requiredRecord->status === 'available') {
                                $this->installPackage($requiredHandle);
                            }
                            $this->enablePackage($requiredHandle);
                        }
                    } else {
                        throw new \Exception("Required template package '{$requiredHandle}' was not found.");
                    }
                }
            }
        }

        // We assume the package is in our local source for MVP
        $pluginPath = Craft::getAlias('@site7/studio'); // resolves to src/
        $basePath = dirname($pluginPath); // resolves to plugins/site7-studio/
        $packagePath = $basePath . '/packages/' . $handle;
        if (!is_dir($packagePath)) {
            $packagePath = $basePath . '/tests/fixtures/packages/' . $handle;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        $generatedResources = [];
        try {
            // 1. Generate Craft Resources
            if ($record->type === 'section' && is_dir($packagePath)) {
                $generatedResources = \site7\studio\Site7Studio::getInstance()->craftResourceGenerator->generateResources($packagePath);

                // Save generated resource UIDs somewhere? For MVP, we will rely on handle conventions

                // Step 5: record this install's baseline checksum for the
                // template it just wrote to @templates/_blocks/ - only when
                // generateResources() actually copied it (never for a
                // skipped-due-to-conflict template.twig, which isn't this
                // package's content as installed).
                $installedTemplate = $generatedResources['installedTemplate'] ?? null;
                if ($installedTemplate) {
                    $checksum = PackageArchiveHelper::computeFileChecksum($installedTemplate['absolutePath']);
                    if ($checksum !== null) {
                        \site7\studio\Site7Studio::getInstance()->installedFileBaseline->record(
                            $record->id,
                            $installedTemplate['blockHandle'],
                            $installedTemplate['targetPath'],
                            $record->version,
                            $checksum,
                        );
                    }
                }
            }

            // Step 8.2: install every explicitly package-owned file (Step
            // 8.1's ownedFiles) - never automatic, only whatever the
            // manifest actually declares. Empty on every package that
            // predates ownedFiles, or whose author selected none - the
            // common case - so this is a no-op loop for those, unchanged
            // from Step 8.1 install behavior.
            if (is_dir($packagePath)) {
                $this->installOwnedFiles($record, $packagePath);
            }

            if ($record->type === 'template' && TemplateInstaller::isFormatV2($packagePath)) {
                (new TemplateInstaller())->installContent($packagePath);
            }
            if ($record->type === 'starter-kit' && KitInstaller::isFormatV2($packagePath)) {
                (new KitInstaller())->installContent($record, $packagePath);
            }

            // NOTE: Install does NOT link to Matrix. User must click "Enable" to do that.

            // 2. Update status
            $record->status = 'installed';
            if (!$record->save()) {
                throw new \Exception("Could not save package status.");
            }

            $transaction->commit();

            $this->invalidateCraftCaches();

            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            // Rollback generated project config resources
            \site7\studio\Site7Studio::getInstance()->craftResourceGenerator->removeResources($generatedResources);
            Craft::error("Installation failed for {$handle}: " . $e->getMessage(), __METHOD__);
            echo "Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            return false;
        }
    }

    /**
     * Step 6 - safely updates $handle's already-installed files toward
     * $toVersionRecordId's archived content, using PackageUpdatePlanner's
     * baseline/live/incoming three-way comparison. Only files it classifies
     * as a safe update or safe removal are ever touched; a locally-modified
     * or conflicting file is left completely untouched. Never a blind
     * "force update" - every item in the returned plan reports what
     * happened (or didn't) to that specific file, so a partial result
     * (some files updated, others left alone) is never misreported as a
     * full success.
     *
     * Does not touch the package's own manifest.json/version - that's
     * governed by Sync From Source (Step 2) / VersionManagerService
     * (Step 4) on the package's authoring side, a separate concern from
     * "which version's files does this SITE currently have installed."
     *
     * @return array<int, array{targetPath: string, resourceHandle: string,
     *   result: string, baselineChecksum: string, liveChecksum: ?string,
     *   incomingChecksum: ?string, message: string, applied: bool}>
     * @throws \Exception if the package or the target version (with a real archive) can't be found.
     */
    public function updateInstalledFiles(string $handle, int $toVersionRecordId): array
    {
        $record = $this->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception("Package '{$handle}' was not found.");
        }

        $versionRecord = PackageVersionRecord::findOne(['id' => $toVersionRecordId, 'packageId' => $record->id]);
        if (!$versionRecord || !$versionRecord->archivePath || !file_exists($versionRecord->archivePath)) {
            throw new \Exception("Version #{$toVersionRecordId} has no real archive to update from.");
        }

        $plugin = \site7\studio\Site7Studio::getInstance();
        /** @var PackageUpdatePlanner $planner */
        $planner = $plugin->packageUpdatePlanner;
        $baselineService = $plugin->installedFileBaseline;

        $baselines = $baselineService->allForPackage($record->id);
        $targetPaths = array_map(fn(array $b) => $b['targetPath'], $baselines);
        $incomingFiles = $planner->resolveIncomingChecksums($handle, $versionRecord->archivePath, $targetPaths);

        $plan = $planner->plan($record->id, $incomingFiles);

        // Deliberately does NOT call PackageBackupService here, even though
        // it's the existing backup mechanism: that service keeps only the
        // LATEST backup per handle (unlinking any previous one it finds via
        // its own handle-prefixed glob - see its own docblock), which would
        // silently delete an OLDER PackageVersionRecord's own archivePath
        // whenever that older archive happens to already live in the same
        // marketplace-repo folder (true for a package's very first version,
        // backed up there automatically on import). Confirmed live during
        // this step's own verification - calling it here destroyed v1's
        // archive out from under its own version row. Every version already
        // has its own permanent, independent archive via
        // VersionManagerService::createVersion() (Step 4) - that IS this
        // operation's safety net; a live file is only ever replaced with
        // bytes read straight back out of $versionRecord->archivePath
        // below, and applySafeFileUpdate() only advances the baseline after
        // verifying the written file's checksum matches exactly what was
        // promised.
        $root = dirname(rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/'));

        foreach ($plan as &$item) {
            if ($item['result'] === PackageUpdatePlanner::RESULT_SAFE_UPDATE) {
                $item['applied'] = $this->applySafeFileUpdate($record, $handle, $versionRecord, $item, $root);
            } elseif ($item['result'] === PackageUpdatePlanner::RESULT_SAFE_REMOVAL) {
                $item['applied'] = $this->applySafeFileRemoval($record, $item, $root, $baselineService);
            } else {
                $item['applied'] = false;
            }
        }
        unset($item);

        return $plan;
    }

    /**
     * Extracts $item's file from $versionRecord's archive and copies it onto
     * the live site - but only advances the baseline (via
     * InstalledFileBaselineService, Step 5's sole writer) if the file that
     * actually landed on disk checksums to exactly what the plan promised.
     * A failed/partial write must never look identical to a clean baseline
     * advance.
     */
    private function applySafeFileUpdate(PackageRecord $record, string $handle, PackageVersionRecord $versionRecord, array $item, string $root): bool
    {
        $tempDir = Craft::getAlias('@storage') . '/runtime/site7-studio/update-apply/' . uniqid('', true);
        try {
            // Step 8.2: same shared resolver resolveIncomingChecksums() used
            // to compute $item['incomingChecksum'] in the first place - a
            // single source of truth for "what archive entry does this
            // targetPath mean," covering both the built-in template.twig
            // mapping and Step 8.1's ownedFiles.
            $entryName = \site7\studio\Site7Studio::getInstance()->packageUpdatePlanner
                ->resolveArchiveEntryName($handle, $versionRecord->archivePath, $item['targetPath']);
            if ($entryName === null) {
                return false;
            }
            PackageArchiveHelper::extractZip($versionRecord->archivePath, $tempDir, [$entryName]);
            $extractedPath = $tempDir . '/' . $entryName;
            if (!is_file($extractedPath)) {
                return false;
            }

            if (!self::isRelativeSafePath((string)$item['targetPath'])) {
                return false;
            }
            $absoluteTarget = $root . '/' . $item['targetPath'];
            \craft\helpers\FileHelper::createDirectory(dirname($absoluteTarget));
            copy($extractedPath, $absoluteTarget);

            $writtenChecksum = PackageArchiveHelper::computeFileChecksum($absoluteTarget);
            if ($writtenChecksum === null || $writtenChecksum !== $item['incomingChecksum']) {
                return false;
            }

            \site7\studio\Site7Studio::getInstance()->installedFileBaseline->record(
                $record->id,
                $item['resourceHandle'],
                $item['targetPath'],
                $versionRecord->version,
                $writtenChecksum,
            );

            return true;
        } finally {
            if (is_dir($tempDir)) {
                \craft\helpers\FileHelper::removeDirectory($tempDir);
            }
        }
    }

    /**
     * Removes a file the incoming version no longer contains - only ever
     * called by updateInstalledFiles() for a RESULT_SAFE_REMOVAL item,
     * meaning the live file still matched its baseline (never a locally
     * modified file). Removes the baseline row too, since there is nothing
     * left on disk for it to describe.
     */
    private function applySafeFileRemoval(PackageRecord $record, array $item, string $root, $baselineService): bool
    {
        if (!self::isRelativeSafePath((string)$item['targetPath'])) {
            return false;
        }
        $absoluteTarget = $root . '/' . $item['targetPath'];
        if (file_exists($absoluteTarget)) {
            @unlink($absoluteTarget);
        }
        $baselineService->remove($record->id, $item['targetPath']);
        return true;
    }

    /**
     * Step 8.2 - copies each of $record's explicitly package-owned files
     * (Step 8.1's manifest ownedFiles, never auto-discovered) from the
     * package's own directory to its real Craft-root-relative target path,
     * and registers a Step 5 baseline for each one actually copied. Plain
     * file I/O - no Craft resource/field/entry-type API needed, so this
     * lives here rather than in CraftResourceService, which owns Craft
     * resource generation specifically. A no-op loop for the common case
     * (ownedFiles empty), unchanged behavior for every existing package.
     *
     * Applies the same "don't silently overwrite a file this package
     * doesn't recognize" guard CraftResourceService's own template copy
     * already uses: only copies if the target is missing or already
     * byte-identical to the source - a pre-existing, unrelated file
     * sitting at the same target path is left alone and reported, not
     * clobbered.
     */
    private function installOwnedFiles(PackageRecord $record, string $packagePath): void
    {
        $manifest = $record->getManifest();
        if (!$manifest || empty($manifest->ownedFiles)) {
            return;
        }

        $root = dirname(rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/'));
        $baselineService = \site7\studio\Site7Studio::getInstance()->installedFileBaseline;

        foreach ($manifest->ownedFiles as $owned) {
            $sourcePath = (string)($owned['sourcePath'] ?? '');
            $targetPath = (string)($owned['targetPath'] ?? '');
            if ($sourcePath === '' || $targetPath === '') {
                continue;
            }

            // The manifest decides where the file lands, and a package can
            // come from an imported archive: only frontend sources, never
            // outside them, never PHP.
            if (!self::isAllowedOwnedFileTarget($targetPath) || !self::isRelativeSafePath($sourcePath)) {
                $this->_lastInstallWarnings[] = "Owned file '{$targetPath}' of package '{$record->handle}' is outside the frontend source folders - skipped.";
                Craft::warning("Refused owned file target '{$targetPath}' (source '{$sourcePath}') of package '{$record->handle}'.", __METHOD__);
                continue;
            }

            // One owner per file: another package's baseline means it's theirs.
            $otherOwner = \site7\studio\records\PackageInstalledFileRecord::find()
                ->where(['targetPath' => $targetPath])
                ->andWhere(['not', ['packageId' => $record->id]])
                ->one();
            if ($otherOwner) {
                $ownerHandle = PackageRecord::findOne($otherOwner->packageId)?->handle ?? "#{$otherOwner->packageId}";
                $this->_lastInstallWarnings[] = "Owned file '{$targetPath}' already belongs to package '{$ownerHandle}' - skipped.";
                continue;
            }

            $absoluteSource = $packagePath . '/' . $sourcePath;
            if (!is_file($absoluteSource)) {
                Craft::warning("Owned file '{$sourcePath}' declared by package '{$record->handle}' was not found in its package directory - skipped.", __METHOD__);
                continue;
            }

            $absoluteTarget = $root . '/' . $targetPath;
            if (is_file($absoluteTarget) && file_get_contents($absoluteTarget) !== file_get_contents($absoluteSource)) {
                $this->_lastInstallWarnings[] = "Owned file '{$targetPath}' already exists with different content - skipped to avoid overwriting it.";
                continue;
            }

            \craft\helpers\FileHelper::createDirectory(dirname($absoluteTarget));
            copy($absoluteSource, $absoluteTarget);

            $checksum = PackageArchiveHelper::computeFileChecksum($absoluteTarget);
            if ($checksum !== null) {
                $baselineService->record($record->id, $record->handle, $targetPath, $record->version, $checksum);
            }
        }
    }

    /**
     * Where a package-owned file may be written (docs/21): a frontend source
     * file - the same places FrontendToolingScanner offers as candidates
     * (src/ under the project root or under frontend/, assets/, theme/) -
     * and never a PHP file.
     */
    public static function isAllowedOwnedFileTarget(string $targetPath): bool
    {
        if (!self::isRelativeSafePath($targetPath)) {
            return false;
        }
        if (preg_match('/\.(php\d?|phtml|phar)$/i', $targetPath)) {
            return false;
        }

        return (bool)preg_match('#^(?:(?:frontend|assets|theme)/)?src/.+#', $targetPath);
    }

    /** Relative, forward-slash path with no "..", "." or empty segments. */
    public static function isRelativeSafePath(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Enables a package (updates status to 'enabled').
     */
    public function enablePackage(string $handle): bool
    {
        if (!$this->isLicensed($handle)) {
            return false;
        }

        $record = $this->getPackageByHandle($handle);
        if ($record && $record->type === 'section') {
            $this->linkToMatrix($handle);
        }
        $result = $this->updatePackageStatus($handle, 'enabled');
        $this->invalidateCraftCaches();
        return $result;
    }

    /**
     * Licence gate shared by installPackage() and enablePackage(), so every
     * caller (CP buttons, archive import, Local Repository, dependency
     * cascade, console, import flows) is checked - not only
     * PackageActionController. The reason is surfaced via
     * getLastInstallWarnings().
     */
    private function isLicensed(string $handle): bool
    {
        if (Site7Studio::getInstance()->commercePackages->canInstallOrEnable($handle)) {
            return true;
        }

        $message = "'{$handle}' is not included in your current plan or purchases.";
        $this->_lastInstallWarnings[] = $message;
        Craft::warning($message, __METHOD__);
        return false;
    }

    /**
     * Disables a package (updates status to 'disabled').
     */
    public function disablePackage(string $handle): bool
    {
        $record = $this->getPackageByHandle($handle);
        if ($record && $record->type === 'section') {
            $this->unlinkFromMatrix($handle);
        }
        $result = $this->updatePackageStatus($handle, 'disabled');
        $this->invalidateCraftCaches();
        return $result;
    }

    public function removePackage(string $handle): bool
    {
        $record = $this->getPackageByHandle($handle);
        if ($record && $record->type === 'section') {
            // Deliberately does NOT call craftResourceGenerator->removePackageResources()
            // here - that deletes the entry type outright, which cascades into Craft
            // soft-deleting every existing nested entry of that type (see
            // Entries::handleDeletedEntryType()). Uninstalling (unlike deletePackage()'s
            // permanent removal) must leave the entry type and its fields intact so a
            // later installPackage() reuses the exact same one via
            // CraftResourceService::createMatrixEntryType()'s existing-handle check,
            // instead of generating a new entry type that orphans any content already
            // authored against the old one.
            $this->unlinkFromMatrix($handle);
        }

        $result = $this->updatePackageStatus($handle, 'available');
        $this->invalidateCraftCaches();
        return $result;
    }

    /**
     * The CP's Remove button: uninstalls a package but keeps it in the
     * Library, so Install rebuilds it from there. A Section's block type,
     * fields and _blocks template are deleted with deletePackage()'s same
     * usage-checked removal (anything still used is kept and reported via
     * getLastDeleteWarnings()). Other package types generate no Craft
     * resources of their own, so only their status changes. removePackage()
     * stays the soft step Reinstall uses, which must keep the block type.
     */
    public function uninstallPackage(string $handle): bool
    {
        $this->_lastDeleteWarnings = [];

        $record = $this->getPackageByHandle($handle);
        if (!$record) {
            return false;
        }

        if ($record->type === 'section') {
            $this->unlinkFromMatrix($handle);
            $packagePath = $this->getPackagePath($handle);
            // A block imported from this site (Import Existing Section) is
            // its own source: its block type, fields and template are the
            // author's real ones, never "generated" - keep them.
            if ((new \site7\studio\repositories\SectionImportSourceRepository())->findByPackageId((int)$record->id)) {
                $this->_lastDeleteWarnings[] = "'{$record->name}' was imported from this site, so its block type, fields and template are its source and were kept.";
            } elseif ($packagePath) {
                $this->_lastDeleteWarnings = \site7\studio\Site7Studio::getInstance()->craftResourceGenerator->removePackageResources($packagePath);
            }
        }

        $result = $this->updatePackageStatus($handle, 'available');
        $this->invalidateCraftCaches();
        return $result;
    }

    /** @var array<string, string>|null block (entry type) handle => Section package handle */
    private ?array $_sectionPackagesByBlock = null;

    /**
     * The Section package in this site's Library that provides a block type
     * (its matrix.yaml), whether it was imported here or came from the
     * Library (Commerce24, a Starter Kit) - so the same block is never
     * imported again as a second package.
     */
    public function sectionPackageForEntryType(string $entryTypeHandle): ?PackageRecord
    {
        if ($this->_sectionPackagesByBlock === null) {
            $this->_sectionPackagesByBlock = [];
            foreach ($this->getAllPackages() as $record) {
                $path = $record->type === 'section' ? $this->getPackagePath($record->handle) : null;
                if (!$path || !is_file("{$path}/matrix.yaml")) {
                    continue;
                }
                foreach ((array)(\Symfony\Component\Yaml\Yaml::parseFile("{$path}/matrix.yaml")['blocks'] ?? []) as $block) {
                    if (!empty($block['handle'])) {
                        $this->_sectionPackagesByBlock[$block['handle']] ??= $record->handle;
                    }
                }
            }
        }
        $handle = $this->_sectionPackagesByBlock[$entryTypeHandle] ?? null;

        return $handle ? $this->getPackageByHandle($handle) : null;
    }

    /**
     * A Theme or Library Starter Kit (docs/49, 51): it sets up a whole site,
     * so it installs only through the Install screen (ThemeInstaller /
     * KitInstaller), never the generic installPackage(), and once it has set
     * up the site it can't be disabled or removed. Blueprint kits (docs/32)
     * aren't included: they install like any package.
     */
    public function setsUpTheSite(string $handle): bool
    {
        $record = $this->getPackageByHandle($handle);

        return $record !== null && ($record->type === 'theme'
            || ($record->type === 'starter-kit' && \site7\studio\services\starterkit\KitInstaller::isFormatV2($this->getPackagePath($handle))
                && !$this->isPack($handle)));
    }

    /**
     * A page pack (docs/51 §2a): a Library Starter Kit with only some pages,
     * no menus or demo content. It adds pages to a site, so it installs,
     * disables and is removed like a page - it doesn't set up the site.
     */
    public function isPack(string $handle): bool
    {
        $path = $this->getPackagePath($handle);
        if (!\site7\studio\services\starterkit\KitInstaller::isFormatV2($path)) {
            return false;
        }
        $meta = json_decode((string)file_get_contents("{$path}/" . \site7\studio\services\starterkit\KitBuilder::META_FILE), true);

        return !empty($meta['pack']);
    }

    /** Whether a Theme or Library Starter Kit has set up this site: enabled, or disabled before that was refused. */
    public static function hasSetUpTheSite(?\site7\studio\records\PackageRecord $record): bool
    {
        return $record !== null && in_array($record->status, ['enabled', 'disabled'], true);
    }

    /**
     * Permanently deletes a package: unlinks/removes any generated Craft
     * resources first (same as removePackage()), then deletes its DB record
     * and its entire folder from disk. Irreversible - the caller is
     * responsible for confirming with the user and checking usage first.
     */
    public function deletePackage(string $handle): bool
    {
        $this->_lastDeleteWarnings = [];

        $record = $this->getPackageByHandle($handle);
        if (!$record) {
            return false;
        }

        if ($record->type === 'section') {
            $this->unlinkFromMatrix($handle);
        }

        $packagePath = $this->getPackagePath($handle);
        if ($packagePath && is_dir($packagePath)) {
            $this->_lastDeleteWarnings = \site7\studio\Site7Studio::getInstance()->craftResourceGenerator->removePackageResources($packagePath);
        }

        $record->delete();

        if ($packagePath && is_dir($packagePath)) {
            \craft\helpers\FileHelper::removeDirectory($packagePath);
        }

        $this->invalidateCraftCaches();
        return true;
    }

    /**
     * Permanently removes a package from the Library WITHOUT ever touching
     * the live Craft resources it's linked to - the safe undo for "imported
     * the wrong Section/Entry Type by mistake." Unlike deletePackage(), this
     * never calls craftResourceGenerator->removePackageResources(), so the
     * real Entry Type/Fields it was imported from (or generated) are left
     * completely alone, exactly as if the package had never been imported.
     *
     * The site7_section_import_sources linkage row (if any) is deleted
     * automatically via its ON DELETE CASCADE FK on packageId, which also
     * means the same live Entry Type can be cleanly re-imported afterward
     * without tripping the "already imported" duplicate guard.
     */
    public function detachPackage(string $handle): bool
    {
        $record = $this->getPackageByHandle($handle);
        if (!$record) {
            return false;
        }

        if ($record->type === 'section') {
            $this->unlinkFromMatrix($handle);
        }

        $packagePath = $this->getPackagePath($handle);

        $record->delete();

        if ($packagePath && is_dir($packagePath)) {
            \craft\helpers\FileHelper::removeDirectory($packagePath);
        }

        $this->invalidateCraftCaches();
        return true;
    }

    /**
     * Invalidates all relevant Craft CMS internal caches after modifying
     * package resources or Matrix field configurations.
     *
     * This is critical for same-process operations (e.g. CLI tests that
     * install → enable → save content in one invocation). Craft caches
     * field instances in a private `_fields` property on the Fields service;
     * without clearing it the Matrix field retains its old entryTypes list
     * and silently drops blocks for newly linked types.
     */
    private function invalidateCraftCaches(): void
    {
        // 1. (Removed) This used to call ProjectConfig::rebuild() after every
        // package operation. A rebuild regenerates the whole project config
        // from the database, which drops anything that only lives in project
        // config - verified 2026-10-02: it stripped the add-menu groups from
        // every block type of the page-builder field. Every change this
        // plugin makes goes through Craft's services (saveField(),
        // ProjectConfig::set()), which keep project config in sync already.

        // 2. Refresh the DB schema cache (new columns from new fields)
        Craft::$app->getDb()->getSchema()->refresh();

        // 3. Bump the field version counter
        Craft::$app->getFields()->updateFieldVersion();

        // 4. Refresh the entry types registry
        Craft::$app->getEntries()->refreshEntryTypes();

        // 5. Clear the private _fields cache on the Fields service so
        //    getFieldById() returns a completely fresh instance next time.
        //    There is no public API for this in Craft 5.
        try {
            $fieldsRef = new \ReflectionProperty(\craft\services\Fields::class, '_fields');
            $fieldsRef->setAccessible(true);
            $fieldsRef->setValue(Craft::$app->getFields(), null);
            
            $layoutsRef = new \ReflectionProperty(\craft\services\Fields::class, '_layouts');
            $layoutsRef->setAccessible(true);
            $layoutsRef->setValue(Craft::$app->getFields(), null);
        } catch (\ReflectionException $e) {
            Craft::warning('Could not clear Fields caches: ' . $e->getMessage(), __METHOD__);
        }

        // 6. Clear _entryTypes on any already-loaded Matrix field instances.
        //    Matrix.php caches entry types in a private _entryTypes array that
        //    is populated once at init. If the field object is held by something
        //    (e.g. a FieldLayout already stored on an Entry), the stale list
        //    causes _createEntriesFromSerializedData to silently skip new types.
        $settings = \site7\studio\Site7Studio::getInstance()->getSettings();
        if (!empty($settings->matrixFieldId)) {
            $matrixField = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
            if ($matrixField instanceof \craft\fields\Matrix) {
                // Re-populate _entryTypes from the fresh project config
                $matrixField->setEntryTypes(
                    array_map(fn($et) => $et->id, $matrixField->getEntryTypes())
                );
                // If the above produces the old list (because the field was just re-loaded
                // from cache), fall back to reading the project config directly
                try {
                    $ref = new \ReflectionProperty(\craft\fields\Matrix::class, '_entryTypes');
                    $ref->setAccessible(true);
                    $ref->setValue($matrixField, []);
                    // Force re-population from the DB by reading the field's settings
                    $fieldConfig = Craft::$app->getProjectConfig()->get("fields.{$matrixField->uid}");
                    if (isset($fieldConfig['settings']['entryTypes'])) {
                        $entryTypeIds = [];
                        foreach ($fieldConfig['settings']['entryTypes'] as $assocItem) {
                            if (isset($assocItem['__assoc__'])) {
                                foreach ($assocItem['__assoc__'] as [$key, $val]) {
                                    if ($key === 'uid') {
                                        $entryType = Craft::$app->getEntries()->getEntryTypeByUid($val);
                                        if ($entryType) {
                                            $entryTypeIds[] = $entryType;
                                        }
                                    }
                                }
                            }
                        }
                        if (!empty($entryTypeIds)) {
                            $ref->setValue($matrixField, $entryTypeIds);
                        }
                    }
                } catch (\ReflectionException $e) {
                    Craft::warning('Could not reset Matrix._entryTypes: ' . $e->getMessage(), __METHOD__);
                }
            }
        }

        // 7. Invalidate element query caches
        Craft::$app->getElements()->invalidateCachesForElementType(\craft\elements\Entry::class);
    }

    /**
     * Helper to update the status of a package in the repository.
     */
    private function updatePackageStatus(string $handle, string $status): bool
    {
        $record = $this->getPackageByHandle($handle);
        if ($record) {
            $record->status = $status;
            return $record->save();
        }
        return false;
    }

    /**
     * Links a package's Entry Types to the configured Matrix field.
     */
    public function linkToMatrix(string $handle): void
    {
        $this->modifyMatrixLink($handle, true);
    }

    /**
     * Unlinks a package's Entry Types from the configured Matrix field.
     */
    public function unlinkFromMatrix(string $handle): void
    {
        $this->modifyMatrixLink($handle, false);
    }

    private function modifyMatrixLink(string $handle, bool $add): void
    {
        $settings = \site7\studio\Site7Studio::getInstance()->getSettings();
        if (!$settings->matrixFieldId) {
            return;
        }

        $fieldsService = Craft::$app->getFields();
        $matrixField = $fieldsService->getFieldById($settings->matrixFieldId);
        
        if (!$matrixField || !($matrixField instanceof \craft\fields\Matrix)) {
            return;
        }

        $packagePath = $this->getPackagePath($handle);
        if (!$packagePath) {
            return;
        }

        $matrixYamlPath = $packagePath . '/matrix.yaml';
        if (!file_exists($matrixYamlPath)) {
            return;
        }

        $matrixData = \Symfony\Component\Yaml\Yaml::parseFile($matrixYamlPath);
        if (!isset($matrixData['blocks']) || !is_array($matrixData['blocks'])) {
            return;
        }

        // Edits the field's own entry type list in place, so the other block
        // types keep their add-menu groups (re-saving the field through
        // setEntryTypes() dropped them).
        $entriesService = Craft::$app->getEntries();
        $schemaService = new \site7\studio\services\import\SectionSchemaService();

        foreach ($matrixData['blocks'] as $blockDef) {
            $blockHandle = $blockDef['handle'] ?? null;
            $entryType = $blockHandle ? $entriesService->getEntryTypeByHandle($blockHandle) : null;
            if ($entryType) {
                $schemaService->linkToMatrix($matrixField, $entryType->uid, $add);
            }
        }
    }
}
