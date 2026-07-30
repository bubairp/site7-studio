<?php

namespace site7\studio\services\support;

use Craft;
use craft\helpers\FileHelper;
use site7\studio\services\PackageExportService;

/**
 * Automatically backs a package up into the Local Repository
 * (storage/site7-studio/marketplace-repo/, the same folder
 * LocalMarketplaceRepository already reads .s7pkg files from for the
 * Marketplace's Repository/Updates tabs) every time it's created or
 * imported - so an accidental deletion (of the package, or of a Craft
 * resource it declares) is always recoverable via the existing Marketplace
 * "install from Repository" flow, no manual archive or database recovery
 * needed.
 *
 * Reuses PackageExportService unchanged - this class only decides *when* a
 * backup happens and where the resulting .s7pkg ends up, never how one is
 * built. Keeps only the latest backup per package handle: each new
 * create/import replaces the previous one rather than accumulating an
 * unbounded history.
 */
class PackageBackupService
{
    public function backupToLocalRepository(string $handle): void
    {
        try {
            $exportedPath = (new PackageExportService())->exportPackage($handle, true);
        } catch (\Throwable $e) {
            // A failed backup must never block the create/import it's
            // piggybacking on - logged only.
            Craft::warning("Could not back up package '{$handle}' to the Local Repository: " . $e->getMessage(), 'site7-studio');
            return;
        }

        $repoPath = Craft::getAlias('@storage') . '/site7-studio/marketplace-repo';
        FileHelper::createDirectory($repoPath);

        // Keep only the latest backup for this handle. A plain "<handle>-*"
        // glob isn't safe on its own - it would also match a different
        // package whose handle happens to share this one as a prefix (e.g.
        // "hero-banner" matching "hero-banner-2"'s files) - so every
        // candidate's own bundle-manifest.json rootHandle is checked before
        // anything is removed.
        foreach (glob($repoPath . '/' . $handle . '-*.s7pkg') ?: [] as $previous) {
            if ($this->rootHandleOf($previous) === $handle) {
                @unlink($previous);
            }
        }

        $destPath = $repoPath . '/' . basename($exportedPath);
        rename($exportedPath, $destPath);
    }

    private function rootHandleOf(string $zipPath): ?string
    {
        try {
            $contents = PackageArchiveHelper::readEntry($zipPath, 'bundle-manifest.json');
            $data = $contents !== null ? (json_decode($contents, true) ?: []) : [];
            return $data['rootHandle'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
