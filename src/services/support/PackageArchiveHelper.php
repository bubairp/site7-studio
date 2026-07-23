<?php

namespace site7\studio\services\support;

use craft\helpers\FileHelper;

/**
 * Shared, stateless zip/checksum primitives used by both PackageExportService
 * and PackageImportService (and LocalMarketplaceRepository, when it peeks at
 * an archive's bundle-manifest.json). Kept as static helpers rather than a
 * DI-registered service since neither side needs to be an instance of the
 * other, and export/import must compute checksums identically.
 */
class PackageArchiveHelper
{
    /**
     * Produces a deterministic hash of every file's contents under $path,
     * independent of filesystem mtimes/permissions - two directories with
     * identical file contents always produce the same checksum, on any OS.
     * Used both to record a package's checksum at export time and to verify
     * an archive wasn't corrupted/tampered with at import time.
     */
    public static function computeDirectoryChecksum(string $path): string
    {
        $path = rtrim($path, '/\\');
        $fileHashes = [];

        if (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile()) {
                    $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($path))), '/');
                    $fileHashes[$relative] = hash_file('sha256', $file->getPathname());
                }
            }
        }

        ksort($fileHashes);

        $lines = [];
        foreach ($fileHashes as $relative => $hash) {
            $lines[] = "{$relative}:{$hash}";
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * Recursively adds every file under $path into an open ZipArchive,
     * nested under $zipPrefix (e.g. "packages/hero-banner").
     */
    public static function addDirectoryToZip(\ZipArchive $zip, string $path, string $zipPrefix): void
    {
        $path = rtrim($path, '/\\');
        $zipPrefix = trim($zipPrefix, '/');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($path))), '/');
            $zipEntry = $zipPrefix . '/' . $relative;

            if ($item->isDir()) {
                $zip->addEmptyDir($zipEntry);
            } else {
                $zip->addFile($item->getPathname(), $zipEntry);
            }
        }
    }

    /**
     * Extracts a .s7pkg (or any zip) to $destDir. Pass $onlyEntries to
     * extract just specific entries (e.g. a repository catalog scan that
     * only needs bundle-manifest.json, not every bundled package's files).
     *
     * @throws \Exception if the archive can't be opened.
     */
    public static function extractZip(string $zipPath, string $destDir, ?array $onlyEntries = null): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \Exception("Could not open archive: {$zipPath}");
        }

        FileHelper::createDirectory($destDir);

        if ($onlyEntries === null) {
            $zip->extractTo($destDir);
        } else {
            $zip->extractTo($destDir, $onlyEntries);
        }

        $zip->close();
    }
}
