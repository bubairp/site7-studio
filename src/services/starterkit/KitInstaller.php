<?php

namespace site7\studio\services\starterkit;

use Craft;
use craft\helpers\App;
use site7\studio\records\PackageRecord;
use site7\studio\services\library\LibraryDistribution;
use site7\studio\services\sitekit\SiteKitContent;
use site7\studio\services\theme\ThemeInstaller;
use site7\studio\services\PackageManagerService;
use site7\studio\Site7Studio;

/**
 * Installs a Library Starter Kit (format v2, docs/51_LIBRARY_STARTER_KIT.md)
 * onto a fresh Craft install with Site7 Studio: its Theme (ThemeInstaller),
 * then - in a new process, since the Theme replaced vendor/ and the plugin
 * list - the kit package itself through the package manager, whose
 * starter-kit cascade installs every Template (and their blocks);
 * installContent() then adds menus, sitemap data and demo content.
 */
class KitInstaller extends ThemeInstaller
{
    public static function isFormatV2(?string $packagePath): bool
    {
        return $packagePath !== null && is_file("{$packagePath}/" . KitBuilder::META_FILE);
    }

    /**
     * The Theme sections a kit needs (docs/49 §2c): a page pack's own list,
     * every optional section (null) for a full kit.
     *
     * @return string[]|null
     */
    public static function kitSections(string $packagePath): ?array
    {
        $meta = json_decode((string)@file_get_contents("{$packagePath}/" . KitBuilder::META_FILE), true) ?: [];

        return is_array($meta['sections'] ?? null) ? $meta['sections'] : null;
    }

    /** Whether a Starter Kit (full or base, not a page pack) has set up this site. */
    public static function siteHasKit(): bool
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        foreach ($packageManager->getAllPackages() as $record) {
            if ($record->type === 'starter-kit' && $packageManager->setsUpTheSite($record->handle) && PackageManagerService::hasSetUpTheSite($record)) {
                return true;
            }
        }

        return false;
    }

    /** Whether $handle is a page pack, here or in Commerce24's catalog. */
    public function isPack(string $handle): bool
    {
        return Site7Studio::getInstance()->packageManager->isPack($handle)
            || !empty((new LibraryDistribution())->catalog()[$handle]['metadata']['library']['pack']);
    }

    /** The base kit (docs/51 §2b) a page pack's site starts from: in this site's Library, or Commerce24's. */
    public function baseKitHandle(): ?string
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        foreach ($packageManager->getAllPackages() as $record) {
            $path = $record->type === 'starter-kit' ? $packageManager->getPackagePath($record->handle) : null;
            $meta = self::isFormatV2($path) ? json_decode((string)file_get_contents("{$path}/" . KitBuilder::META_FILE), true) : null;
            if (!empty($meta['base'])) {
                return $record->handle;
            }
        }
        foreach ((new LibraryDistribution())->catalog() as $handle => $entry) {
            if (($entry['type'] ?? null) === 'starter-kit' && !empty($entry['metadata']['library']['base'])) {
                return $handle;
            }
        }

        return null;
    }

    /**
     * @return array{errors: string[], warnings: string[], meta: array|null, theme: string|null, themeInstalled: bool}
     */
    public function validateKit(string $handle): array
    {
        $result = ['errors' => [], 'warnings' => [], 'meta' => null, 'theme' => null, 'themeInstalled' => false, 'remote' => false, 'download' => [], 'downloadSize' => 0];
        // The structure step writes project config, which Craft refuses
        // while admin changes are off. Say so before any download when it's
        // already known: the env override wins over any config file, and
        // once a Theme is installed this site's config is the Theme's.
        // Otherwise the Theme's own config/general.php decides, checked by
        // validateTheme() before anything changes.
        $override = App::env('CRAFT_ALLOW_ADMIN_CHANGES');
        $adminChangesOff = $override !== null
            ? !(App::parseBooleanEnv($override) ?? true)
            : $this->siteHasTheme() && !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
        if ($adminChangesOff) {
            $result['errors'][] = 'Admin changes are turned off on this site, so its project config is read-only and the kit can\'t set up its structure. Set CRAFT_ALLOW_ADMIN_CHANGES=true in .env, then try again.';
            return $result;
        }
        $packageManager = Site7Studio::getInstance()->packageManager;
        $dir = $packageManager->getPackagePath($handle);
        if (!$dir) {
            return $this->validateRemoteKit($handle, $result);
        }
        $manifest = $dir ? json_decode((string)@file_get_contents("{$dir}/manifest.json"), true) : null;
        if (!self::isFormatV2($dir) || ($manifest['type'] ?? null) !== 'starter-kit') {
            $result['errors'][] = "'{$handle}' is not a Library Starter Kit.";
            return $result;
        }
        $result['meta'] = json_decode((string)file_get_contents("{$dir}/" . KitBuilder::META_FILE), true);
        $theme = $manifest['requires']['themes'][0] ?? null;
        $result['theme'] = $theme;

        foreach ($manifest['requires']['templates'] ?? [] as $template) {
            if (!$packageManager->getPackagePath($template)) {
                $result['errors'][] = "The Template package '{$template}' isn't in this site's Library.";
            }
        }
        if ($result['errors']) {
            return $result;
        }

        $packageManager->discoverPackages();
        if (PackageManagerService::hasSetUpTheSite($packageManager->getPackageByHandle((string)$theme))) {
            $result['themeInstalled'] = true;
        } else {
            $themeCheck = $this->validateTheme((string)$theme);
            $result['errors'] = array_merge($result['errors'], $themeCheck['errors']);
            $result['warnings'] = array_merge($result['warnings'], $themeCheck['warnings']);
        }

        return $result;
    }

    /**
     * A kit Commerce24 offers that isn't in this site's Library yet
     * (docs/52): its packages, their entitlements and this site's
     * freshness are checked before anything is downloaded; the Theme's
     * own checks run after download, in installKit().
     */
    private function validateRemoteKit(string $handle, array $result): array
    {
        $check = (new LibraryDistribution())->check($handle);
        $result['remote'] = true;
        $result['errors'] = $check['errors'];
        $result['warnings'] = $check['warnings'];
        $result['download'] = $check['download'];
        $result['downloadSize'] = $check['downloadSize'];
        if ($check['kit']) {
            $result['meta'] = $check['kit']['metadata']['library'] ?? null;
            $result['theme'] = $check['kit']['requires']['themes'][0] ?? null;
        }
        $result['themeInstalled'] = $result['theme'] !== null
            && PackageManagerService::hasSetUpTheSite(Site7Studio::getInstance()->packageManager->getPackageByHandle($result['theme']));
        if (!$result['themeInstalled'] && count(Craft::$app->getEntries()->getAllSections()) > 0) {
            $result['errors'][] = 'This site already has content structure. A Starter Kit sets up a fresh Craft install.';
        }

        return $result;
    }

    private function siteHasTheme(): bool
    {
        foreach (Site7Studio::getInstance()->packageManager->getAllPackages() as $record) {
            if ($record->type === 'theme' && PackageManagerService::hasSetUpTheSite($record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{errors: string[], warnings: string[], backup: string|null}
     */
    public function installKit(string $handle, ?callable $log = null): array
    {
        $log ??= fn(string $line) => null;
        // A page pack adds pages to a site: on a site no kit set up, the
        // base kit comes first - Home, About, Contact, the menus.
        $first = null;
        if (!self::siteHasKit() && $this->isPack($handle)) {
            $baseKit = $this->baseKitHandle();
            if ($baseKit === null) {
                return ['errors' => ['A page pack adds pages to a site set up by a Starter Kit - install one first.'], 'warnings' => [], 'backup' => null];
            }
            $log("A page pack adds pages to a website: installing {$baseKit} first…");
            $first = $this->installKit($baseKit, $log);
            if ($first['errors']) {
                return $first;
            }
        }
        $validation = $this->validateKit($handle);
        $result = ['errors' => $validation['errors'], 'warnings' => array_values(array_unique(array_merge($first['warnings'] ?? [], $validation['warnings']))), 'backup' => $first['backup'] ?? null];
        if ($result['errors']) {
            return $result;
        }

        if ($validation['remote']) {
            $log(sprintf('Downloading %d packages (%s) from Commerce24…', count($validation['download']), Craft::$app->getFormatter()->asShortSize($validation['downloadSize'])));
            if ($errors = (new LibraryDistribution())->download($validation['download'], $log)) {
                $result['errors'] = $errors;
                return $result;
            }
            $validation = $this->validateKit($handle);
            $result['errors'] = $validation['errors'];
            $result['warnings'] = array_values(array_unique(array_merge($result['warnings'], $validation['warnings'])));
            if ($result['errors']) {
                return $result;
            }
        }

        if (!$validation['themeInstalled']) {
            $log("Installing the Theme {$validation['theme']}…");
            $theme = $this->installTheme((string)$validation['theme'], $log);
            $result['backup'] = $theme['backup'];
            // The Theme's own check repeats validateKit()'s warnings.
            $result['warnings'] = array_values(array_unique(array_merge($result['warnings'], $theme['warnings'])));
            if ($theme['errors']) {
                $result['errors'] = $theme['errors'];
                return $result;
            }
        }

        $root = $this->root();
        $php = App::phpExecutable() ?? 'php';
        $log('Installing ' . ($validation['meta']['templates'] ?? '') . ' pages with their blocks' . (empty($validation['meta']['pack']) ? ', then menus and demo content…' : '…'));
        if ($this->run([$php, "{$root}/craft", 'site7-studio/starter-kit/apply', $handle], $root, 'pages and content', $result, $log)) {
            $this->run([$php, "{$root}/craft", 'clear-caches/all'], $root, 'caches', $result, $log);
        }

        return $result;
    }

    /**
     * The kit step (its own process): the package manager installs the kit
     * package - the cascade installs its Templates - then enables it.
     *
     * @return string[] errors
     */
    public function applyKit(string $handle): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $packageManager->discoverPackages();
        // Without its folder the kit has no manifest, so nothing would install.
        if (!$packageManager->getPackagePath($handle)) {
            return ["The Starter Kit '{$handle}' is not in this site's Library (packages/{$handle} is missing)."];
        }
        try {
            $ok = $packageManager->installPackage($handle) && $packageManager->enablePackage($handle);
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }

        return $ok ? [] : array_merge(["'{$handle}' was not installed."], $packageManager->getLastInstallWarnings());
    }

    /**
     * Called by PackageManagerService::installPackage() once the cascade
     * installed the kit's Templates: every one must now be enabled, then
     * the kit's own content goes in.
     *
     * @return array<string, int> rows imported per table
     * @throws \Exception
     */
    public function installContent(PackageRecord $record, string $packagePath): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $missing = array_filter(
            $record->getManifest()?->requires['templates'] ?? [],
            fn($template) => $packageManager->getPackageByHandle($template)?->status !== 'enabled'
        );
        if ($missing) {
            throw new \Exception('These pages were not installed: ' . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? '…' : ''));
        }

        $content = new SiteKitContent();
        if ($missingStructure = $content->missingStructure($packagePath)) {
            throw new \Exception('This site is missing structure the kit\'s content needs: ' . implode(', ', array_slice($missingStructure, 0, 10)));
        }

        return $content->import($packagePath);
    }
}
