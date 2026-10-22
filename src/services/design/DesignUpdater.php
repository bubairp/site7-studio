<?php

namespace site7\studio\services\design;

use Craft;
use craft\helpers\FileHelper;
use site7\studio\records\PackageRecord;
use site7\studio\services\library\LibraryUpdater;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\Site7Studio;

/**
 * Updates an installed design to the version now in the Library (docs/60),
 * up or down. Each file in designs/<handle> goes through the update rule
 * (LibraryUpdater::decide() -> PackageUpdatePlanner::classify()): untouched
 * here = the new version's, edited here = kept and reported. The baseline is
 * the file's checksum recorded at install or last update
 * (InstalledFileBaselineService, it travels with the database), falling back
 * to the previous Library copy kept in $baselineDir. web/designs/<handle> is
 * replaced whole. On any failure the design's files are put back.
 */
class DesignUpdater
{
    public function apply(PackageRecord $record, string $baselineDir, string $dir, callable $log): array
    {
        DesignInstaller::assertCanChangeFiles();
        $handle = DesignInstaller::designHandle($dir);
        $previousHandle = is_file("{$baselineDir}/" . DesignBuilder::META_FILE) ? DesignInstaller::designHandle($baselineDir) : $handle;
        if ($previousHandle !== $handle) {
            throw new \Exception("The new version is design '{$handle}', the installed one '{$previousHandle}'.");
        }
        $root = DesignInstaller::root();
        $report = LibraryUpdater::emptyReport();
        $baselines = Site7Studio::getInstance()->installedFileBaseline;
        $packageId = (int)$record->id;

        $rows = [];
        foreach ($baselines->allForPackage($packageId) as $row) {
            $rows[$row['targetPath']] = $row['checksum'];
        }
        $incomingDir = "{$dir}/files/designs/{$handle}";
        $previousDir = "{$baselineDir}/files/designs/{$handle}";
        $paths = [];
        foreach (DesignInstaller::relativeFiles($incomingDir) as $relative) {
            $paths["designs/{$handle}/{$relative}"] = $relative;
        }
        foreach (array_keys($rows) as $targetPath) {
            $paths[$targetPath] ??= substr($targetPath, strlen("designs/{$handle}/"));
        }
        foreach (DesignInstaller::relativeFiles($previousDir) as $relative) {
            $paths["designs/{$handle}/{$relative}"] ??= $relative;
        }

        $backup = $this->backup($handle, $root);
        try {
            foreach ($paths as $targetPath => $relative) {
                $target = "{$root}/{$targetPath}";
                $b = $rows[$targetPath] ?? PackageArchiveHelper::computeFileChecksum("{$previousDir}/{$relative}");
                $l = PackageArchiveHelper::computeFileChecksum($target);
                $i = PackageArchiveHelper::computeFileChecksum("{$incomingDir}/{$relative}");

                if ($i === null) {
                    // Gone from the new version: removed if untouched here.
                    if ($l !== null && $l === $b) {
                        unlink($target);
                        $baselines->remove($packageId, $targetPath);
                        $report['trashed'][] = "file {$targetPath}";
                    } elseif ($l !== null) {
                        $report['kept'][] = "file {$targetPath} (removed upstream, edited here)";
                    } else {
                        $baselines->remove($packageId, $targetPath);
                    }
                    continue;
                }
                $decision = LibraryUpdater::decide($b, $l, $i);
                if ($decision === 'apply') {
                    DesignInstaller::copyFile("{$incomingDir}/{$relative}", $target);
                    $report[$l === null ? 'added' : 'updated'][] = "file {$targetPath}";
                    $baselines->record($packageId, $handle, $targetPath, (string)$record->version, $i);
                } elseif ($decision === 'kept') {
                    $report['kept'][] = "file {$targetPath}";
                    // The baseline stays the version the edit was made on.
                } else {
                    $report['unchanged']++;
                    if ($l === $i) {
                        $baselines->record($packageId, $handle, $targetPath, (string)$record->version, $i);
                    }
                }
            }
            DesignInstaller::replaceBuild($dir, $handle, $root);
            $report['updated'][] = "build web/designs/{$handle}";
        } catch (\Throwable $e) {
            $this->restore($backup, $handle, $root);
            throw $e;
        }
        FileHelper::removeDirectory($backup);
        if (DesignInstaller::activeHandle() === $handle) {
            $log("  {$record->name} is the active design: caches cleared after the update.");
        }

        return $report;
    }

    /** Copies designs/<handle> and web/designs/<handle> aside. */
    private function backup(string $handle, string $root): string
    {
        $dir = Craft::getAlias('@storage') . '/site7-studio/update-backups/' . date('Ymd-His') . "-design-{$handle}-" . substr(md5(uniqid('', true)), 0, 6);
        foreach (["designs/{$handle}", "web/designs/{$handle}"] as $relative) {
            if (is_dir("{$root}/{$relative}")) {
                FileHelper::copyDirectory("{$root}/{$relative}", "{$dir}/{$relative}");
            }
        }

        return $dir;
    }

    private function restore(string $backup, string $handle, string $root): void
    {
        foreach (["designs/{$handle}", "web/designs/{$handle}"] as $relative) {
            if (is_dir("{$root}/{$relative}")) {
                FileHelper::removeDirectory("{$root}/{$relative}");
            }
            if (is_dir("{$backup}/{$relative}")) {
                FileHelper::copyDirectory("{$backup}/{$relative}", "{$root}/{$relative}");
            }
        }
    }
}
