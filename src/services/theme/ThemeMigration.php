<?php

namespace site7\studio\services\theme;

use Craft;
use site7\studio\records\PackageInstalledFileRecord;
use site7\studio\services\design\DesignLoader;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\Site7Studio;

/**
 * Moves an existing site onto the site7/theme package (docs/60 §3, Theme in
 * vendor phase 5): its copies of the base theme's templates in templates/
 * go, so the package renders them; its edits stay, as overrides.
 *
 * A Theme update already removes the Theme-shipped templates a site didn't
 * edit. What's left are the core blocks' templates their Section packages
 * copied into templates/_blocks, and templates the site edited. Per site
 * file at a path the theme package has (recorded overrides are left alone):
 *
 *   same as the theme's       deleted, with its tracking row
 *   as installed, but older   kept for now: the theme's newer template may use
 *                             fields this site's block doesn't have yet. A
 *                             Library update brings it level; the next run
 *                             deletes it. (Run Library updates first.)
 *   edited here               kept and recorded as an override, so
 *                             theme/overrides reports later theme changes
 *
 * "As installed" is the file's site7_installed_files row (Section packages'
 * block templates). A file without a row that differs from the theme's was
 * kept by a Theme update as edited here, so it counts as an edit.
 */
class ThemeMigration
{
    public const REMOVE = 'remove';
    public const WAIT = 'wait';
    public const OVERRIDE = 'override';

    private string $siteTemplates;

    /**
     * @param callable(string $targetPath): ?array{packageId: int, checksum: string} $row the tracking row of a path relative to the Craft root
     * @param callable(int $packageId, string $targetPath): void $removeRow
     */
    public function __construct(
        string $siteTemplates,
        private ?string $themeTemplates,
        private ThemeOverrides $overrides,
        private $row,
        private $removeRow,
    ) {
        $this->siteTemplates = rtrim(str_replace('\\', '/', $siteTemplates), '/');
    }

    /** This site: its templates/, the installed theme package, site7_installed_files. */
    public static function forSite(): self
    {
        $baselines = Site7Studio::getInstance()->installedFileBaseline;

        return new self(
            Craft::getAlias('@root') . '/templates',
            DesignLoader::themeTemplatesPath(),
            ThemeOverrides::forSite(),
            function(string $targetPath): ?array {
                $row = PackageInstalledFileRecord::find()->where(['targetPath' => $targetPath])->one();
                return $row ? ['packageId' => (int)$row->packageId, 'checksum' => (string)$row->checksum] : null;
            },
            fn(int $packageId, string $targetPath) => $baselines->remove($packageId, $targetPath),
        );
    }

    /**
     * What a run would do, per path under templates/.
     *
     * @return array<string, string> path => REMOVE | WAIT | OVERRIDE
     */
    public function plan(): array
    {
        if ($this->themeTemplates === null) {
            throw new \Exception('This site has no site7/theme package yet: update the Theme first, it brings the package.');
        }
        $tracked = $this->overrides->report();
        $plan = [];
        if (!is_dir($this->siteTemplates)) {
            return $plan;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->siteTemplates, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->siteTemplates)), '/');
            $theme = "{$this->themeTemplates}/{$path}";
            if (!is_file($theme) || (isset($tracked[$path]) && $tracked[$path]['state'] !== ThemeOverrides::UNTRACKED)) {
                continue;
            }
            $live = PackageArchiveHelper::computeFileChecksum($file->getPathname());
            if ($live === PackageArchiveHelper::computeFileChecksum($theme)) {
                $plan[$path] = self::REMOVE;
            } elseif (($row = ($this->row)("templates/{$path}")) !== null && $row['checksum'] === $live) {
                $plan[$path] = self::WAIT;
            } else {
                $plan[$path] = self::OVERRIDE;
            }
        }
        ksort($plan);

        return $plan;
    }

    /**
     * Applies plan(): deletes, records overrides.
     *
     * @return array{removed: string[], waiting: string[], overrides: string[]}
     */
    public function run(): array
    {
        $report = ['removed' => [], 'waiting' => [], 'overrides' => []];
        foreach ($this->plan() as $path => $action) {
            if ($action === self::REMOVE) {
                $file = "{$this->siteTemplates}/{$path}";
                if (!unlink($file) && is_file($file)) {
                    throw new \RuntimeException("Could not delete templates/{$path}. Nothing after it was changed; run again.");
                }
                if (($row = ($this->row)("templates/{$path}")) !== null) {
                    ($this->removeRow)((int)$row['packageId'], "templates/{$path}");
                }
                $this->removeEmptyFolders(dirname($file));
                $report['removed'][] = $path;
            } elseif ($action === self::OVERRIDE) {
                $this->overrides->track($path, 'kept by theme/migrate: edited on this site');
                $report['overrides'][] = $path;
            } else {
                $report['waiting'][] = $path;
            }
        }

        return $report;
    }

    /** Folders the deletes emptied, up to templates/ itself. */
    private function removeEmptyFolders(string $dir): void
    {
        $dir = str_replace('\\', '/', $dir);
        while ($dir !== $this->siteTemplates && str_starts_with($dir, $this->siteTemplates . '/') && is_dir($dir) && !(new \FilesystemIterator($dir))->valid()) {
            rmdir($dir);
            $dir = dirname($dir);
        }
    }
}
