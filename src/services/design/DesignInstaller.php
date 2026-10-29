<?php

namespace site7\studio\services\design;

use Craft;
use craft\helpers\FileHelper;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\services\support\ThemeVersion;
use site7\studio\records\PackageRecord;
use site7\studio\services\library\LibraryUpdater;
use site7\studio\Site7Studio;

/**
 * Installs and removes a design package (docs/60): its files/designs/<handle>
 * go to designs/<handle> with a baseline per file (InstalledFileBaselineService,
 * so an update can tell the customer's edits apart), its files/web/designs/<handle>
 * build replaces web/designs/<handle> whole. Never writes anywhere else.
 */
class DesignInstaller
{
    /**
     * @return array report (LibraryUpdater::emptyReport() shape)
     * @throws \Exception
     */
    public function install(PackageRecord $record): array
    {
        $packageDir = self::packageDir($record);
        $handle = self::designHandle($packageDir);
        self::assertCanChangeFiles();
        self::assertThemeVersion($packageDir, (string)$record->name);
        $root = self::root();
        $report = LibraryUpdater::emptyReport();
        $baselines = Site7Studio::getInstance()->installedFileBaseline;

        foreach (self::relativeFiles("{$packageDir}/files/designs/{$handle}") as $relative) {
            $source = "{$packageDir}/files/designs/{$handle}/{$relative}";
            $targetPath = "designs/{$handle}/{$relative}";
            $target = "{$root}/{$targetPath}";
            $incoming = PackageArchiveHelper::computeFileChecksum($source);
            if (is_file($target) && PackageArchiveHelper::computeFileChecksum($target) !== $incoming) {
                // A file of the same name is already here: it's the site's.
                $report['kept'][] = "file {$targetPath}";
            } else {
                self::copyFile($source, $target);
                $report['added'][] = "file {$targetPath}";
            }
            $baselines->record((int)$record->id, $handle, $targetPath, (string)$record->version, (string)$incoming);
        }
        self::replaceBuild($packageDir, $handle, $root);

        self::setStatus($record, 'installed');

        return $report;
    }

    /**
     * Removes the design's files: unchanged ones are deleted, ones edited
     * here are kept and reported, the build goes. The active design can't be
     * removed.
     *
     * @throws \Exception
     */
    public function remove(PackageRecord $record): array
    {
        $packageDir = self::packageDir($record);
        $handle = self::designHandle($packageDir);
        self::assertCanChangeFiles();
        if (self::activeHandle() === $handle) {
            throw new \Exception("'{$record->name}' is the active design. Switch to another design first.");
        }
        $root = self::root();
        $report = LibraryUpdater::emptyReport();
        $baselines = Site7Studio::getInstance()->installedFileBaseline;

        foreach ($baselines->allForPackage((int)$record->id) as $row) {
            $target = "{$root}/{$row['targetPath']}";
            if (!is_file($target)) {
                $baselines->remove((int)$record->id, $row['targetPath']);
                continue;
            }
            if (PackageArchiveHelper::computeFileChecksum($target) === $row['checksum']) {
                unlink($target);
                $report['trashed'][] = "file {$row['targetPath']}";
            } else {
                $report['kept'][] = "file {$row['targetPath']} (edited here)";
            }
            $baselines->remove((int)$record->id, $row['targetPath']);
        }
        if (!$report['kept']) {
            self::removeEmptyDirectories("{$root}/designs/{$handle}");
        }
        if (is_dir("{$root}/web/designs/{$handle}")) {
            FileHelper::removeDirectory("{$root}/web/designs/{$handle}");
        }

        self::setStatus($record, 'available');

        return $report;
    }

    // ------------------------------------------------------------- helpers

    /** The design's own handle (canopy) from the package's design.json. */
    public static function designHandle(string $packageDir): string
    {
        $meta = json_decode((string)@file_get_contents("{$packageDir}/" . DesignBuilder::META_FILE), true) ?: [];
        $handle = DesignLoader::validHandle($meta['handle'] ?? null);
        if ($handle === null) {
            throw new \Exception('This design package has no valid handle in its design.json.');
        }

        return $handle;
    }

    /** The active design's handle (setting or SITE7_DESIGN), null for the default. */
    public static function activeHandle(): ?string
    {
        return DesignLoader::validHandle(Site7Studio::getInstance()->getSettings()->activeDesign);
    }

    /**
     * web/designs/<handle> becomes the package's build, written next to it and
     * swapped in, so a page never loads half a build. Build output is never
     * merged: it's generated (docs/60).
     */
    public static function replaceBuild(string $packageDir, string $handle, string $root): void
    {
        $source = "{$packageDir}/files/web/designs/{$handle}";
        if (!is_dir($source)) {
            return;
        }
        $forbidden = DesignBuilder::forbiddenFiles($source);
        if ($forbidden) {
            throw new \Exception('The design build contains files a design may not carry: ' . implode(', ', array_slice($forbidden, 0, 5)));
        }
        // Built output never goes into the site's git: each server builds it
        // with `npm run build` (docs/60 §4), whatever the root .gitignore says.
        FileHelper::createDirectory("{$root}/web/designs");
        if (!is_file("{$root}/web/designs/.gitignore")) {
            file_put_contents("{$root}/web/designs/.gitignore", "# Built by `npm run build` on each server (Site7 Studio designs)\n*\n!.gitignore\n");
        }
        $target = "{$root}/web/designs/{$handle}";
        $staging = "{$target}.installing";
        if (is_dir($staging)) {
            FileHelper::removeDirectory($staging);
        }
        FileHelper::copyDirectory($source, $staging);
        if (is_dir($target)) {
            FileHelper::removeDirectory($target);
        }
        rename($staging, $target);
    }

    /** Relative paths of every file under $dir (forbidden files refused). @return string[] */
    public static function relativeFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $forbidden = DesignBuilder::forbiddenFiles($dir);
        if ($forbidden) {
            throw new \Exception('The design contains files a design may not carry: ' . implode(', ', array_slice($forbidden, 0, 5)));
        }
        $files = [];
        foreach (FileHelper::findFiles($dir) as $file) {
            $relative = ltrim(substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $dir))), '/');
            if (!str_contains("/{$relative}/", '/../')) {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }

    public static function copyFile(string $source, string $target): void
    {
        FileHelper::createDirectory(dirname($target));
        if (!copy($source, $target)) {
            throw new \Exception("Could not write {$target}.");
        }
    }

    public static function packageDir(PackageRecord $record): string
    {
        $dir = Site7Studio::getInstance()->packageManager->getPackagePath($record->handle);
        if (!$dir || $record->type !== 'design') {
            throw new \Exception("'{$record->handle}' isn't a design package in this site's Library.");
        }

        return $dir;
    }

    public static function root(): string
    {
        return rtrim(str_replace('\\', '/', Craft::getAlias('@root')), '/');
    }

    /** Designs change files and the active design is project config: development site only (docs/60 §4). */
    /**
     * Refuses a design whose design.json names a newer Theme than this site has
     * (ThemeVersion): the site's build tooling comes with the Theme, and an older
     * one would skip building it at the next deploy. Install and update both ask.
     *
     * @throws \Exception
     */
    public static function assertThemeVersion(string $packageDir, string $name): void
    {
        $meta = json_decode((string)@file_get_contents("{$packageDir}/" . DesignBuilder::META_FILE), true) ?: [];
        $minimum = (string)($meta['minimumThemeVersion'] ?? '');
        if (!ThemeVersion::satisfies($minimum)) {
            throw new \Exception(ThemeVersion::refusal($name !== '' ? $name : (string)($meta['name'] ?? 'This design'), $minimum));
        }
    }

    public static function assertCanChangeFiles(): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new \Exception('Designs are installed, updated and removed on the development site, then deployed: admin changes are off here.');
        }
    }

    /**
     * Designs are only ever 'available' or 'installed', never 'enabled': a
     * plan change disables enabled packages (PackageService::syncEntitlements),
     * and a live site must not change look because of one (docs/60 §4).
     */
    private static function setStatus(PackageRecord $record, string $status): void
    {
        $record->status = $status;
        $record->save(false);
    }

    private static function removeEmptyDirectories(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_reverse(FileHelper::findDirectories($dir)) as $sub) {
            @rmdir($sub);
        }
        @rmdir($dir);
    }
}
