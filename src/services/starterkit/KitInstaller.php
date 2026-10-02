<?php

namespace site7\studio\services\starterkit;

use Craft;
use craft\helpers\App;
use site7\studio\records\PackageRecord;
use site7\studio\services\sitekit\SiteKitContent;
use site7\studio\services\theme\ThemeInstaller;
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
     * @return array{errors: string[], warnings: string[], meta: array|null, theme: string|null, themeInstalled: bool}
     */
    public function validateKit(string $handle): array
    {
        $result = ['errors' => [], 'warnings' => [], 'meta' => null, 'theme' => null, 'themeInstalled' => false];
        $packageManager = Site7Studio::getInstance()->packageManager;
        $dir = $packageManager->getPackagePath($handle);
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
        if ($packageManager->getPackageByHandle((string)$theme)?->status === 'enabled') {
            $result['themeInstalled'] = true;
        } else {
            $themeCheck = $this->validateTheme((string)$theme);
            $result['errors'] = array_merge($result['errors'], $themeCheck['errors']);
            $result['warnings'] = array_merge($result['warnings'], $themeCheck['warnings']);
        }

        return $result;
    }

    /**
     * @return array{errors: string[], warnings: string[], backup: string|null}
     */
    public function installKit(string $handle, ?callable $log = null): array
    {
        $log ??= fn(string $line) => null;
        $validation = $this->validateKit($handle);
        $result = ['errors' => $validation['errors'], 'warnings' => $validation['warnings'], 'backup' => null];
        if ($result['errors']) {
            return $result;
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
        $log('Installing ' . ($validation['meta']['templates'] ?? '') . ' pages with their blocks, then menus and demo content…');
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
