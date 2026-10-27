<?php

namespace site7\studio\services\theme;

use Composer\InstalledVersions;
use Craft;
use site7\studio\services\design\DesignLoader;
use site7\studio\services\PackageManagerService;

/**
 * A site's overrides of the base theme (docs/60 §3): its own copy of a
 * template the site7/theme package has, in templates/ at the same path,
 * which Craft renders instead of the package's.
 *
 * theme/override copies the package's file and records it in
 * templates/_site7/overrides.json: the file's checksum when copied and the
 * package version. A later theme release that changes the file is then
 * reported, because the override hides that change. The ledger is site
 * code, in git with the overrides; Craft never routes a path under _.
 *
 * States, per site file at a path the theme also has (or once had):
 *   current    the theme's file is still the one copied
 *   changed    the theme changed it since: review the copy
 *   removed    the theme no longer has it: the copy may be unused
 *   untracked  not in the ledger (copied by hand, or before this command):
 *              no base to compare with
 */
class ThemeOverrides
{
    public const LEDGER = '_site7/overrides.json';

    public const CURRENT = 'current';
    public const CHANGED = 'changed';
    public const REMOVED = 'removed';
    public const UNTRACKED = 'untracked';

    private string $siteTemplates;

    public function __construct(string $siteTemplates, private ?string $themeTemplates, private ?string $themeVersion = null)
    {
        $this->siteTemplates = rtrim(str_replace('\\', '/', $siteTemplates), '/');
    }

    /** This site: its templates/ (not a design's) and the installed theme package. */
    public static function forSite(): self
    {
        return new self(Craft::getAlias('@root') . '/templates', DesignLoader::themeTemplatesPath(), self::installedThemeVersion());
    }

    /** The installed site7/theme version, e.g. "1.8.0" or "dev-main ab907dc"; null without the package. */
    public static function installedThemeVersion(): ?string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled(DesignLoader::THEME_PACKAGE)) {
            return null;
        }
        $version = (string)InstalledVersions::getPrettyVersion(DesignLoader::THEME_PACKAGE);
        $reference = (string)InstalledVersions::getReference(DesignLoader::THEME_PACKAGE);

        return str_starts_with($version, 'dev-') && $reference !== '' ? "{$version} " . substr($reference, 0, 7) : $version;
    }

    /**
     * Copies the theme's $template into templates/ and records it.
     *
     * @param string $template path under templates/, e.g. "_blocks/ctaBanner.twig" (".twig" may be left off)
     * @return string the path copied
     * @throws \Exception when there's no theme, no such theme file, or the site already has one
     */
    public function copy(string $template): string
    {
        if ($this->themeTemplates === null) {
            throw new \Exception('This site has no site7/theme package, so there is nothing to override: its templates are all in templates/.');
        }
        $relative = ltrim(str_replace('\\', '/', trim($template)), '/');
        if (!is_file("{$this->themeTemplates}/{$relative}") && is_file("{$this->themeTemplates}/{$relative}.twig")) {
            $relative .= '.twig';
        }
        if ($relative === '' || !PackageManagerService::isRelativeSafePath($relative) || str_starts_with($relative, '_site7/')) {
            throw new \Exception("Not a template path: {$template}");
        }
        if (!is_file("{$this->themeTemplates}/{$relative}")) {
            throw new \Exception("The theme has no {$relative}.");
        }
        if (is_file("{$this->siteTemplates}/{$relative}")) {
            throw new \Exception("templates/{$relative} already exists: this site already overrides it.");
        }

        self::makeDir(dirname("{$this->siteTemplates}/{$relative}"));
        copy("{$this->themeTemplates}/{$relative}", "{$this->siteTemplates}/{$relative}");
        $this->track($relative);

        return $relative;
    }

    /**
     * Records the site's existing templates/$relative as an override of the
     * theme's file as it is now (ThemeMigration: a copy edited before the
     * site had the package).
     */
    public function track(string $relative, ?string $note = null): void
    {
        $ledger = $this->ledger();
        $ledger[$relative] = array_filter([
            'checksum' => sha1_file("{$this->themeTemplates}/{$relative}"),
            'theme' => $this->themeVersion,
            'copied' => date('Y-m-d'),
            'note' => $note,
        ], fn($value) => $value !== null);
        $this->saveLedger($ledger);
    }

    /**
     * Every override on this site with its state, by path.
     *
     * @return array<string, array{state: string, theme: ?string, copied: ?string}>
     */
    public function report(): array
    {
        $report = [];
        if ($this->themeTemplates === null) {
            return $report;
        }
        $ledger = $this->ledger();
        foreach ($ledger as $path => $entry) {
            if (!is_file("{$this->siteTemplates}/{$path}")) {
                continue; // the copy was deleted: the theme's file renders again
            }
            $theme = "{$this->themeTemplates}/{$path}";
            $state = !is_file($theme) ? self::REMOVED : (sha1_file($theme) === ($entry['checksum'] ?? null) ? self::CURRENT : self::CHANGED);
            $report[$path] = ['state' => $state, 'theme' => $entry['theme'] ?? null, 'copied' => $entry['copied'] ?? null];
        }
        if (is_dir($this->siteTemplates)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->siteTemplates, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->siteTemplates)), '/');
                if (!isset($report[$path]) && !isset($ledger[$path]) && is_file("{$this->themeTemplates}/{$path}")) {
                    $report[$path] = ['state' => self::UNTRACKED, 'theme' => null, 'copied' => null];
                }
            }
        }
        ksort($report);

        return $report;
    }

    /** report() without the overrides whose base is still current. */
    public function needsReview(): array
    {
        return array_filter($this->report(), fn(array $override) => $override['state'] !== self::CURRENT);
    }

    /** @return array<string, array{checksum?: string, theme?: string, copied?: string}> */
    private function ledger(): array
    {
        $ledger = json_decode((string)@file_get_contents("{$this->siteTemplates}/" . self::LEDGER), true);

        return is_array($ledger) ? $ledger : [];
    }

    private function saveLedger(array $ledger): void
    {
        ksort($ledger);
        self::makeDir(dirname("{$this->siteTemplates}/" . self::LEDGER));
        file_put_contents("{$this->siteTemplates}/" . self::LEDGER, json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** Plain PHP, so this class works without a Craft app (unit tests). */
    private static function makeDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create {$dir}");
        }
    }
}
