<?php

namespace site7\studio\services\theme;

use Craft;
use craft\elements\Entry;
use craft\helpers\App;
use site7\studio\models\Settings;
use site7\studio\services\sitekit\SiteKitFiles;
use site7\studio\services\sitekit\SiteKitInstaller;
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

        if (Craft::$app->getVersion() !== $meta['craftVersion']) {
            $result['errors'][] = "This theme was built on Craft {$meta['craftVersion']}; this site runs Craft " . Craft::$app->getVersion() . '.';
        }
        if (!$this->allowsAdminChangesWith("{$dir}/files/config/general.php")) {
            $result['errors'][] = "The theme's config/general.php turns admin changes off on this site, so its structure couldn't be installed. Set CRAFT_ALLOW_ADMIN_CHANGES=true in .env (or CRAFT_ENVIRONMENT=dev), then try again.";
        }
        $sections = count(Craft::$app->getEntries()->getAllSections());
        if ($sections > 0 || Entry::find()->status(null)->count() > 0) {
            $result['errors'][] = "This site already has content structure ({$sections} sections). A Theme sets up a fresh Craft install.";
        }
        foreach ($meta['pathRepositories'] ?? [] as $path) {
            if (!is_dir("{$root}/{$path}")) {
                $result['errors'][] = "Package source '{$path}' is a local folder on the source site and isn't here - publish it to a Git repository or put it at {$path}/.";
            }
        }
        if (!empty($meta['content']) && !Craft::$app->getDb()->getIsMysql()) {
            $result['errors'][] = 'The theme includes settings content, which can only be imported into MySQL.';
        }
        if (!(new ExecutableFinder())->find('npm')) {
            $result['warnings'][] = 'npm was not found - the frontend will not be built.';
        }
        $targetEnvKeys = array_merge(is_file("{$root}/.env") ? SiteKitFiles::envKeys((string)file_get_contents("{$root}/.env")) : [], array_keys(getenv()));
        if ($missing = SiteKitFiles::missingEnvKeys($meta['envKeys'] ?? [], $targetEnvKeys)) {
            $result['warnings'][] = 'Add these to .env (values are never copied): ' . implode(', ', $missing);
        }

        return $result;
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

        return (bool)(is_array($config) ? ($config['allowAdminChanges'] ?? $config['*']['allowAdminChanges'] ?? true) : true);
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
        if (is_dir("{$dir}/content") && !$this->run([$php, $craft, 'site7-studio/site-kit/import-content', $dir], $root, 'settings content', $result, $log)) {
            return $result;
        }

        $this->buildFrontend($root, $result, $log);

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
        $result = $schemaService->install($schema);

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
        return dirname(Craft::getAlias('@site7/studio')) . '/packages/' . basename($handle);
    }
}
