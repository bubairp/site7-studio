<?php

namespace site7\studio\services\theme;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use site7\studio\services\sitekit\SiteKitContent;
use site7\studio\services\sitekit\SiteKitFiles;
use site7\studio\Site7Studio;

/**
 * Builds a Theme package from this site (docs/49_THEME_PACKAGE.md): the
 * site's structure, code, frontend and settings content - everything a
 * fresh install needs before Section, Template and Starter Kit packages -
 * as a Library package in packages/<handle>/:
 *
 *   manifest.json   type "theme"
 *   theme.json      Craft version, plugins, settings sections, env keys, path repositories
 *   schema.json     structure (ThemeSchemaService), page-builder field without its blocks
 *   plugins.json    plugin project config (settings) and CP element sources
 *   files/          composer.json/lock, templates (minus Section packages' block
 *                   templates), modules, frontend, web/assets, config files
 *   content/        entries of the settings singles (Singles without URLs:
 *                   header, footer, general...) with their nested entries and assets
 */
class ThemeBuilder extends Component
{
    public const META_FILE = 'theme.json';
    public const PLUGINS_FILE = 'plugins.json';

    /** Plugin data that is site configuration rather than page content. */
    public const SETTINGS_PLUGIN_TABLES = ['wheelform_forms', 'wheelform_form_fields'];

    /**
     * @return array{path: string, meta: array}
     * @throws \Exception
     */
    public function build(string $name, ?string $version = null): array
    {
        $root = rtrim(Craft::getAlias('@root'), '/');
        $handle = StringHelper::toKebabCase($name);
        $plugin = Site7Studio::getInstance();
        $pageBuilderUid = $plugin->getSettings()->matrixFieldUid;
        if (!$pageBuilderUid) {
            throw new \Exception('Choose the page-builder field first (Site7 Studio > Settings > General).');
        }

        // Built next to the package and swapped in at the end: a failed
        // rebuild leaves the package - with its version and price - as it was.
        $final = dirname(Craft::getAlias('@site7/studio')) . "/packages/{$handle}";
        $pricingType = self::existingPricingType($final);
        $version ??= self::existingVersion($final);
        $dir = self::startStaging($final);
        FileHelper::createDirectory("{$dir}/files");

        // Structure.
        $schema = (new ThemeSchemaService())->capture($pageBuilderUid);
        file_put_contents("{$dir}/" . ThemeSchemaService::FILE, $this->json($schema));

        // Plugins: every installed plugin except Site7 Studio itself, with its project config.
        $projectConfig = Craft::$app->getProjectConfig();
        $pluginConfigs = [];
        foreach (Craft::$app->getPlugins()->getAllPluginInfo() as $pluginHandle => $info) {
            if (!empty($info['isInstalled']) && $pluginHandle !== 'site7-studio') {
                $pluginConfigs[$pluginHandle] = $projectConfig->get("plugins.{$pluginHandle}");
            }
        }
        file_put_contents("{$dir}/" . self::PLUGINS_FILE, $this->json([
            'plugins' => $pluginConfigs,
            'elementSources' => $projectConfig->get('elementSources'),
        ]));

        // Code: block templates belong to their Section packages.
        $blockTemplates = [];
        $entries = Craft::$app->getEntries();
        foreach ($schema['pageBuilderEntryTypes'] as $uid) {
            if ($entryType = $entries->getEntryTypeByUid($uid)) {
                $blockTemplates["_blocks/{$entryType->handle}.twig"] = true;
            }
        }
        foreach (SiteKitFiles::CODE_DIRECTORIES as $directory) {
            if (is_dir("{$root}/{$directory}")) {
                FileHelper::copyDirectory("{$root}/{$directory}", "{$dir}/files/{$directory}", [
                    'filter' => function(string $path) use ($root, $directory, $blockTemplates) {
                        if (in_array(basename($path), SiteKitFiles::EXCLUDED_DIRECTORY_NAMES, true)) {
                            return false;
                        }
                        $relative = ltrim(str_replace('\\', '/', substr($path, strlen("{$root}/templates"))), '/');
                        return !($directory === 'templates' && isset($blockTemplates[$relative])) ? null : false;
                    },
                ]);
            }
        }
        // Config files, except site7-studio.php: it holds this install's own
        // Commerce24 connection and trusted signing keys.
        $configFiles = [];
        FileHelper::createDirectory("{$dir}/files/config");
        foreach (scandir("{$root}/config") ?: [] as $entry) {
            if ($entry[0] === '.' || !SiteKitFiles::isTravellingConfigFile($entry) || $entry === 'site7-studio.php') {
                continue;
            }
            if (is_dir("{$root}/config/{$entry}")) {
                FileHelper::copyDirectory("{$root}/config/{$entry}", "{$dir}/files/config/{$entry}");
            } else {
                copy("{$root}/config/{$entry}", "{$dir}/files/config/{$entry}");
            }
            $configFiles[] = $entry;
        }
        copy("{$root}/composer.json", "{$dir}/files/composer.json");
        copy("{$root}/composer.lock", "{$dir}/files/composer.lock");

        // Settings and data content: sections without URLs (header, footer,
        // pricing plans, colour options...), except sections visitors post
        // into (reviews) - that's demo content for a Starter Kit.
        $visitorSections = self::visitorSectionUids($projectConfig->get('plugins.guest-entries.settings') ?? []);
        $settingsSections = [];
        foreach ($projectConfig->get('sections') ?? [] as $uid => $section) {
            $hasUrls = false;
            foreach ($section['siteSettings'] ?? [] as $siteSettings) {
                $hasUrls = $hasUrls || !empty($siteSettings['hasUrls']);
            }
            if (!$hasUrls && !isset($visitorSections[$uid])) {
                $settingsSections[$uid] = $section['handle'];
            }
        }
        // Forms are site settings; menus point at pages, so they come with
        // the Starter Kit's content instead.
        $content = (new SiteKitContent())->exportToDir($dir, array_keys($settingsSections), self::SETTINGS_PLUGIN_TABLES);

        $composerJson = json_decode((string)file_get_contents("{$root}/composer.json"), true);
        $meta = [
            'craftVersion' => Craft::$app->getVersion(),
            'plugins' => array_keys($pluginConfigs),
            'pageBuilderField' => Craft::$app->getFields()->getFieldByUid($pageBuilderUid)?->handle,
            'pageBuilderBlocks' => count($schema['pageBuilderEntryTypes']),
            'structure' => array_count_values(array_map(fn($item) => substr($item['path'], 0, strrpos($item['path'], '.')), $schema['items'])),
            'settingsSections' => array_values($settingsSections),
            'content' => $content,
            'configFiles' => $configFiles,
            'pathRepositories' => SiteKitFiles::pathRepositories($composerJson),
            'envKeys' => is_file("{$root}/.env") ? SiteKitFiles::envKeys((string)file_get_contents("{$root}/.env")) : [],
        ];
        file_put_contents("{$dir}/" . self::META_FILE, $this->json($meta));

        file_put_contents("{$dir}/manifest.json", $this->json([
            'schemaVersion' => '1',
            'handle' => $handle,
            'name' => $name,
            'type' => 'theme',
            'version' => $version,
            'author' => Craft::$app->getUser()->getIdentity()?->friendlyName ?? 'Site7',
            'description' => "Structure, templates, frontend and settings of {$name}: " . count($schema['items']) . ' structure items, ' . count($pluginConfigs) . ' plugins. Blocks come as Section packages.',
            'pricingType' => $pricingType,
        ]));
        file_put_contents("{$dir}/README.md", "# {$name}\n\nTheme package built with `site7-studio/theme/build`. See docs/49_THEME_PACKAGE.md.\n");
        $dir = self::commitStaging($dir, $final);

        $plugin->packageManager->discoverPackages();
        $record = $plugin->packageManager->getPackageByHandle($handle);
        if ($record) {
            $record->creatorId = Craft::$app->getUser()->getId()
                ?? \craft\elements\User::find()->admin(true)->status(null)->ids()[0] ?? null;
            $record->save(false);
        }

        return ['path' => $dir, 'meta' => $meta];
    }

    /**
     * The pricingType of the package already at $dir, so a rebuild keeps a
     * price set on it (site7-studio/library/pricing); "free" for a new one.
     */
    public static function existingPricingType(string $dir): string
    {
        $manifest = json_decode((string)@file_get_contents("{$dir}/manifest.json"), true);

        return is_string($manifest['pricingType'] ?? null) && $manifest['pricingType'] !== '' ? $manifest['pricingType'] : 'free';
    }

    /**
     * An empty staging directory next to $final ("<handle>.building"):
     * builders write there and commitStaging() swaps it in, so a failed
     * build never removes the package it was rebuilding.
     */
    public static function startStaging(string $final): string
    {
        $staging = "{$final}.building";
        if (is_dir($staging)) {
            FileHelper::removeDirectory($staging);
        }
        FileHelper::createDirectory($staging);

        return $staging;
    }

    /** Replaces $final with the finished $staging directory; returns $final. */
    public static function commitStaging(string $staging, string $final): string
    {
        if (is_dir($final)) {
            FileHelper::removeDirectory($final);
        }
        rename($staging, $final);

        return $final;
    }

    /**
     * The version of the package already at $dir, so a rebuild keeps it -
     * library/publish raises it when the content changed (docs/53).
     */
    public static function existingVersion(string $dir): string
    {
        $manifest = json_decode((string)@file_get_contents("{$dir}/manifest.json"), true);

        return is_string($manifest['version'] ?? null) && $manifest['version'] !== '' ? $manifest['version'] : '1.0.0';
    }

    /**
     * Sections Guest Entries lets visitors post into.
     *
     * @return array<string, true> section UID => true
     */
    public static function visitorSectionUids(array $guestEntriesSettings): array
    {
        $sections = \craft\helpers\ProjectConfig::unpackAssociativeArrays(['x' => $guestEntriesSettings['sections'] ?? []])['x'];
        $uids = [];
        foreach ((array)$sections as $key => $settings) {
            if (is_array($settings) && !empty($settings['allowGuestSubmissions'])) {
                $uids[(string)($settings['sectionUid'] ?? $key)] = true;
            }
        }

        return $uids;
    }

    private function json(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
