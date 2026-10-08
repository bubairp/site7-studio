<?php

namespace site7\studio\services\theme;

use Craft;
use craft\elements\Entry;
use craft\helpers\App;
use site7\studio\models\Settings;
use site7\studio\services\sitekit\ComposerFiles;
use site7\studio\services\sitekit\SiteKitFiles;
use site7\studio\services\sitekit\SiteKitInstaller;
use site7\studio\services\support\CraftVersion;
use site7\studio\Site7Studio;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Installs a Theme package (docs/49_THEME_PACKAGE.md) onto a fresh Craft
 * install that has Site7 Studio: code and exact Composer packages, plugins,
 * structure, settings content, frontend build. Reuses the Full Site Kit
 * steps (SiteKitInstaller); unlike a kit, it installs into this site - the
 * structure goes through ThemeSchemaService, never a project config swap.
 *
 * Steps after `composer install` run as subprocesses (apply(), content
 * import): this process still has the old autoloader and plugin list.
 */
class ThemeInstaller extends SiteKitInstaller
{
    /**
     * @return array{errors: string[], warnings: string[], meta: array|null, dir: string|null}
     */
    public function validateTheme(string $handle): array
    {
        $result = ['errors' => [], 'warnings' => [], 'meta' => null, 'dir' => null];
        $dir = $this->packageDir($handle);
        $manifest = json_decode((string)@file_get_contents("{$dir}/manifest.json"), true);
        $meta = json_decode((string)@file_get_contents("{$dir}/" . ThemeBuilder::META_FILE), true);
        if (($manifest['type'] ?? null) !== 'theme' || !is_array($meta)) {
            $result['errors'][] = "'{$handle}' is not a Theme package in this site's Library.";
            return $result;
        }
        $result['meta'] = $meta;
        $result['dir'] = $dir;
        $root = $this->root();

        if (!CraftVersion::isCompatible($meta['craftVersion'] ?? null)) {
            $result['errors'][] = 'This theme is for Craft ' . CraftVersion::range($meta['craftVersion']) . " (built on {$meta['craftVersion']}); this site runs Craft " . Craft::$app->getVersion() . '.';
        }
        if (!$this->allowsAdminChangesWith("{$dir}/files/config/general.php")) {
            $result['errors'][] = "The theme's config/general.php turns admin changes off on this site, so its structure couldn't be installed. Set CRAFT_ALLOW_ADMIN_CHANGES=true in .env (or CRAFT_ENVIRONMENT=dev), then try again.";
        }
        $sections = count(Craft::$app->getEntries()->getAllSections());
        if ($sections > 0 || Entry::find()->status(null)->count() > 0) {
            $result['errors'][] = "This site already has content structure ({$sections} sections). A Theme sets up a fresh Craft install.";
        }
        // Folders the Theme brings are fine, and so is Site7 Studio's own
        // (a Theme built before docs/49 §2d still lists it): the site keeps its own.
        $themeLock = json_decode((string)@file_get_contents("{$dir}/files/composer.lock"), true) ?: [];
        $site7Paths = ComposerFiles::packagePaths($themeLock, ComposerFiles::SITE7_PACKAGE);
        foreach ($meta['pathRepositories'] ?? [] as $path) {
            if (!is_dir("{$root}/{$path}") && !is_dir("{$dir}/files/{$path}") && !in_array($path, $site7Paths, true)) {
                $result['errors'][] = "Package source '{$path}' is a local folder on the source site and isn't here - publish it to a Git repository or put it at {$path}/.";
            }
        }
        if (!empty($meta['content']) && !Craft::$app->getDb()->getIsMysql()) {
            $result['errors'][] = 'The theme includes settings content, which can only be imported into MySQL.';
        }
        if (empty($meta['builtFrontend']) && !(new ExecutableFinder())->find('npm')) {
            $result['warnings'][] = 'npm was not found - the frontend will not be built.';
        }
        $targetEnvKeys = array_merge(is_file("{$root}/.env") ? SiteKitFiles::envKeys((string)file_get_contents("{$root}/.env")) : [], array_keys(getenv()));
        if ($missing = SiteKitFiles::missingEnvKeys($meta['envKeys'] ?? [], $targetEnvKeys)) {
            $result['warnings'][] = 'Add these to .env (values are never copied): ' . implode(', ', $missing);
        }

        return $result;
    }

    /**
     * Installs the Theme's Composer packages, keeping this site's own Craft
     * version: the Theme's composer.lock pins the Craft it was built on, and
     * a different one would up- or downgrade Craft under this site's
     * database. Same version: `composer install` from the lock, as before.
     */
    protected function installPackages(string $kit, string $root, array &$result, callable $log): bool
    {
        $kit = $this->withThisSitesPlugin($kit, $root);
        $siteCraft = Craft::$app->getVersion();
        $lockedCraft = self::lockedVersion("{$kit}/composer.lock", 'craftcms/cms');
        if ($lockedCraft === null || ltrim($lockedCraft, 'v') === $siteCraft) {
            return parent::installPackages($kit, $root, $result, $log);
        }

        $php = App::phpExecutable() ?? 'php';
        copy("{$kit}/composer.json", "{$root}/composer.json");
        copy("{$kit}/composer.lock", "{$root}/composer.lock");
        $composerPhar = Craft::$app->getRuntimePath() . '/composer.phar';
        copy(Craft::getAlias('@lib/composer.phar'), $composerPhar);
        $log("The Theme was built on Craft {$lockedCraft}; keeping this site's Craft {$siteCraft}.");

        return $this->run([$php, $composerPhar, 'require', "craftcms/cms:{$siteCraft}", '--update-with-all-dependencies', '--no-interaction', '--no-scripts', '--working-dir=' . $root], $root, 'composer install', $result, $log)
            && $this->run([$php, "{$root}/craft", 'migrate/all', '--interactive=0'], $root, 'craft migrate/all', $result, $log);
    }

    /**
     * A copy of the Theme's composer.json/lock with this site's own Site7
     * Studio - its require line, repository and locked version - in place
     * of whatever the Theme has (docs/49 §2d). Composer then keeps the plugin
     * as this site installed it: from Git, a folder or Packagist.
     *
     * @return string the folder holding the two files
     */
    protected function withThisSitesPlugin(string $kit, string $root): string
    {
        $siteJson = json_decode((string)@file_get_contents("{$root}/composer.json"), true);
        $siteLock = json_decode((string)@file_get_contents("{$root}/composer.lock"), true);
        if (!is_array($siteJson) || !is_array($siteLock)) {
            return $kit;
        }
        [$json, $lock] = ComposerFiles::withPackageFrom(
            json_decode((string)file_get_contents("{$kit}/composer.json"), true),
            json_decode((string)file_get_contents("{$kit}/composer.lock"), true),
            $siteJson,
            $siteLock,
            ComposerFiles::SITE7_PACKAGE
        );
        $merged = Craft::$app->getRuntimePath() . '/site7-theme-composer';
        \craft\helpers\FileHelper::createDirectory($merged);
        file_put_contents("{$merged}/composer.json", ComposerFiles::encode($json));
        file_put_contents("{$merged}/composer.lock", ComposerFiles::encode($lock));

        return $merged;
    }

    /** The version of $package in a composer.lock, or null. */
    private static function lockedVersion(string $lockFile, string $package): ?string
    {
        $lock = json_decode((string)@file_get_contents($lockFile), true) ?: [];
        foreach ($lock['packages'] ?? [] as $entry) {
            if (($entry['name'] ?? null) === $package) {
                return (string)$entry['version'];
            }
        }

        return null;
    }

    /**
     * Whether project config stays writable once $generalConfigFile (the
     * Theme's config/general.php, copied over this site's before the
     * structure step) is in place. The CRAFT_ALLOW_ADMIN_CHANGES env var
     * overrides any config file, as in Craft.
     */
    private function allowsAdminChangesWith(string $generalConfigFile): bool
    {
        $override = App::env('CRAFT_ALLOW_ADMIN_CHANGES');
        if ($override !== null) {
            return App::parseBooleanEnv($override) ?? true;
        }
        if (!is_file($generalConfigFile)) {
            return Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
        }
        try {
            $config = (static fn(string $file) => require $file)($generalConfigFile);
        } catch (\Throwable) {
            return true; // can't tell - the structure step reports it if so
        }
        if ($config instanceof \craft\config\GeneralConfig) {
            return $config->allowAdminChanges;
        }

        if (!is_array($config)) {
            return true;
        }
        // A multi-environment array: this environment's key wins over '*'.
        $env = Craft::$app->env;

        return (bool)($config['allowAdminChanges'] ?? ($env !== null ? $config[$env]['allowAdminChanges'] ?? null : null) ?? $config['*']['allowAdminChanges'] ?? true);
    }

    /**
     * @return array{errors: string[], warnings: string[], backup: string|null}
     */
    public function installTheme(string $handle, ?callable $log = null): array
    {
        $log ??= fn(string $line) => null;
        $validation = $this->validateTheme($handle);
        $result = ['errors' => $validation['errors'], 'warnings' => $validation['warnings'], 'backup' => null];
        if ($validation['errors']) {
            return $result;
        }

        $dir = $validation['dir'];
        $root = $this->root();
        $php = App::phpExecutable() ?? 'php';
        $craft = "{$root}/craft";

        $result['backup'] = $this->backup($root);
        $log("Backed up composer files, config/ and templates/ to {$result['backup']}");

        $this->copyCode("{$dir}/files", $root, $log, $validation['meta']['configFiles'] ?? []);
        $builtFrontend = $validation['meta']['builtFrontend'] ?? null;
        if ($builtFrontend && is_dir("{$dir}/files/{$builtFrontend}")) {
            \craft\helpers\FileHelper::copyDirectory("{$dir}/files/{$builtFrontend}", "{$root}/{$builtFrontend}");
            $log("Copied the built frontend ({$builtFrontend}/)");
        }
        foreach ($validation['meta']['bundledPaths'] ?? [] as $path) {
            if (is_dir("{$dir}/files/{$path}")) {
                \craft\helpers\FileHelper::copyDirectory("{$dir}/files/{$path}", "{$root}/{$path}");
                $log("Copied the plugin folder {$path}/");
            }
        }
        if (!$this->installPackages("{$dir}/files", $root, $result, $log)) {
            return $result;
        }

        $available = $this->availablePluginHandles($root);
        foreach ($validation['meta']['plugins'] as $pluginHandle) {
            if (!in_array($pluginHandle, $available, true)) {
                $result['errors'][] = "Plugin '{$pluginHandle}' was not installed by composer.";
                return $result;
            }
            // Re-running an install that stopped later on finds them installed.
            if (Craft::$app->getPlugins()->isPluginInstalled($pluginHandle)) {
                $log("Plugin {$pluginHandle} is already installed");
                continue;
            }
            if (!$this->run([$php, $craft, 'plugin/install', $pluginHandle], $root, "plugin {$pluginHandle}", $result, $log)) {
                return $result;
            }
        }

        $before = Site7Studio::getInstance()->getSettings()->matrixFieldUid;
        $before = $before ? Craft::$app->getFields()->getFieldByUid($before) : null;
        if (!$this->run([$php, $craft, 'site7-studio/theme/apply', $handle], $root, 'structure', $result, $log)) {
            return $result;
        }
        $log($this->pageBuilderNote($dir, $before));
        if (!$this->run([$php, $craft, 'site7-studio/theme/ensure-singles'], $root, 'singles', $result, $log)) {
            return $result;
        }
        if (is_dir("{$dir}/content") && !$this->run([$php, $craft, 'site7-studio/theme/import-content', $handle], $root, 'settings content', $result, $log)) {
            return $result;
        }

        if ($builtFrontend) {
            $log('Frontend: built files copied, npm not needed (run npm run build in frontend/ to rebuild)');
        } else {
            $this->buildFrontend($root, $result, $log);
        }

        return $result;
    }

    /**
     * The structure step, run as its own process once the plugins exist:
     * schema, plugin settings, CP element sources, Site7's page-builder field.
     *
     * @return array{created: int, reused: int}
     */
    public function apply(string $handle): array
    {
        $dir = $this->packageDir($handle);
        // Before anything creates elements: this site's own rows start
        // above the Library's (Template packages keep the dev site's IDs).
        \site7\studio\services\sitekit\SiteKitContent::reserveLibraryIds();
        $schemaService = new ThemeSchemaService();
        $schema = json_decode((string)file_get_contents("{$dir}/" . ThemeSchemaService::FILE), true);
        // Optional sections come with the pages that need them (addSections()).
        $meta = json_decode((string)file_get_contents("{$dir}/" . ThemeBuilder::META_FILE), true) ?: [];
        $result = $schemaService->install(ThemeSchemaService::withoutSections($schema, $meta['optionalSections'] ?? []));

        $projectConfig = Craft::$app->getProjectConfig();
        $extras = $schemaService->forThisSite(['sourceSites' => $schema['sourceSites'], 'sourceSiteGroups' => $schema['sourceSiteGroups']]
            + json_decode((string)file_get_contents("{$dir}/" . ThemeBuilder::PLUGINS_FILE), true));
        foreach ($extras['plugins'] ?? [] as $pluginHandle => $config) {
            if (isset($config['settings']) && $projectConfig->get("plugins.{$pluginHandle}") !== null) {
                $projectConfig->set("plugins.{$pluginHandle}.settings", $config['settings'], "Site7 Studio theme: {$pluginHandle} settings");
            }
        }
        if (!empty($extras['elementSources'])) {
            $projectConfig->set('elementSources', $extras['elementSources'], 'Site7 Studio theme: element sources');
        }

        $plugin = Site7Studio::getInstance();
        Craft::$app->getPlugins()->savePluginSettings($plugin, Settings::mergeWithStored(['matrixFieldUid' => $schema['pageBuilderField']]));

        $plugin->packageManager->discoverPackages();
        if ($record = $plugin->packageManager->getPackageByHandle($handle)) {
            $record->status = 'enabled';
            $record->save(false);
        }

        return $result;
    }

    /**
     * The settings content step: all of content/, or - with optional
     * sections - only the base part; addSections() brings the rest.
     *
     * @return array<string, int> rows imported per table
     */
    public function importContent(string $handle): array
    {
        $dir = $this->packageDir($handle);
        $meta = json_decode((string)file_get_contents("{$dir}/" . ThemeBuilder::META_FILE), true) ?: [];
        $content = new \site7\studio\services\sitekit\SiteKitContent();
        if (!isset($meta['baseContentIds'])) {
            return $content->import($dir);
        }

        return $content->import($dir, $meta['baseContentIds'], [], ThemeBuilder::SETTINGS_PLUGIN_TABLES);
    }

    /**
     * Adds optional sections of this site's Theme (docs/49 §2c) that aren't
     * here yet, with their settings content: a page, page pack or Starter
     * Kit that needs them calls this before its own content goes in.
     * Sections that aren't optional, or are already here, are left alone.
     *
     * @param string[]|null $handles null: every optional section (a full Starter Kit)
     * @return string[] the sections added
     */
    public function addSections(?array $handles): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $dir = null;
        foreach ($packageManager->getAllPackages() as $record) {
            if ($record->type === 'theme' && \site7\studio\services\PackageManagerService::hasSetUpTheSite($record)) {
                $dir = $packageManager->getPackagePath($record->handle);
            }
        }
        $meta = $dir ? (json_decode((string)@file_get_contents("{$dir}/" . ThemeBuilder::META_FILE), true) ?: []) : [];
        $optional = $meta['optionalSections'] ?? [];
        if (!$optional) {
            return [];
        }

        $entries = Craft::$app->getEntries();
        $add = array_values(array_filter(
            array_unique($handles ?? $optional),
            fn($sectionHandle) => in_array($sectionHandle, $optional, true) && $entries->getSectionByHandle($sectionHandle) === null
        ));
        if (!$add) {
            return [];
        }

        $schema = json_decode((string)file_get_contents("{$dir}/" . ThemeSchemaService::FILE), true);
        (new ThemeSchemaService())->install(ThemeSchemaService::onlySections($schema, $add));
        foreach ($add as $sectionHandle) {
            $section = $entries->getSectionByHandle($sectionHandle);
            if ($section === null) {
                throw new \Exception("Craft did not create the section '{$sectionHandle}'.");
            }
            if ($section->type === \craft\models\Section::TYPE_SINGLE) {
                $entries->saveSection($section);
            }
        }

        $ids = [];
        foreach ($add as $sectionHandle) {
            $ids = array_merge($ids, $meta['sectionContent'][$sectionHandle] ?? []);
        }
        if ($ids) {
            (new \site7\studio\services\sitekit\SiteKitContent())->import($dir, array_values(array_unique($ids)));
        }
        Craft::info('Added the Theme sections ' . implode(', ', $add), 'site7-studio');

        return $add;
    }

    /**
     * The job log line for the page builder apply() sets: the Theme's field
     * replaces whatever was set before.
     */
    private function pageBuilderNote(string $dir, ?\craft\base\FieldInterface $before): string
    {
        $schema = json_decode((string)file_get_contents("{$dir}/" . ThemeSchemaService::FILE), true) ?: [];
        $uid = $schema['pageBuilderField'] ?? null;
        $config = [];
        foreach ($schema['items'] ?? [] as $item) {
            if ($uid && $item['path'] === "fields.{$uid}") {
                $config = $item['config'];
            }
        }
        if (!$uid) {
            return 'The Theme has no page builder field; Site7 Studio\'s Matrix field is unchanged.';
        }
        $note = sprintf("Page builder: '%s' (%s), from the Theme", $config['name'] ?? '?', $config['handle'] ?? $uid);
        if ($before && $before->uid !== $uid) {
            $note .= sprintf(" - replaces '%s' (%s) set before", $before->name, $before->handle);
        }

        return $note;
    }

    /**
     * Every Single gets its one entry, with its URL. Run as its own process
     * after apply(): Craft creates a Single's entry only when an existing
     * section is saved through its service, and in apply()'s process the
     * entries came out without URIs. saveSection() reuses an existing entry
     * and deletes any extras, so this is safe to re-run.
     *
     * @return string[] handles of the Singles
     */
    public function ensureSingles(): array
    {
        $handles = [];
        $entriesService = Craft::$app->getEntries();
        foreach ($entriesService->getAllSections() as $section) {
            if ($section->type === \craft\models\Section::TYPE_SINGLE) {
                $entriesService->saveSection($section);
                $handles[] = $section->handle;
            }
        }

        return $handles;
    }

    private function packageDir(string $handle): string
    {
        return Craft::getAlias('@packages') . '/' . basename($handle);
    }
}
