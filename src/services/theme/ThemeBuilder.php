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
    public function build(string $name, string $version = '1.0.0'): array
    {
        $root = rtrim(Craft::getAlias('@root'), '/');
        $handle = StringHelper::toKebabCase($name);
        $plugin = Site7Studio::getInstance();
        $pageBuilderUid = $plugin->getSettings()->matrixFieldUid;
        if (!$pageBuilderUid) {
            throw new \Exception('Configure the page-builder field in Site7 Studio Setup first.');
        }

        $dir = dirname(Craft::getAlias('@site7/studio')) . "/packages/{$handle}";
        if (is_dir($dir)) {
            FileHelper::removeDirectory($dir);
        }
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

        // Settings content: Singles without URLs.
        $settingsSections = [];
        foreach ($projectConfig->get('sections') ?? [] as $uid => $section) {
            $siteSettings = reset($section['siteSettings']) ?: [];
            if (($section['type'] ?? null) === 'single' && empty($siteSettings['hasUrls'])) {
                $settingsSections[$uid] = $section['handle'];
            }
        }
        $content = $this->exportContent($dir, array_keys($settingsSections));

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
            'pricingType' => 'free',
        ]));
        file_put_contents("{$dir}/README.md", "# {$name}\n\nTheme package built with `site7-studio/theme/build`. See docs/49_THEME_PACKAGE.md.\n");

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
     * Settings content into <dir>/content, via a temporary zip so it's the
     * exact layout SiteKitContent::import() reads.
     */
    private function exportContent(string $dir, array $sectionUids): array
    {
        $zipPath = Craft::$app->getRuntimePath() . '/site7-theme-content-' . StringHelper::randomString(6) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        // Forms are site settings; menus point at pages, so they come with
        // the Starter Kit's content instead.
        $result = (new SiteKitContent())->export($zip, $sectionUids, self::SETTINGS_PLUGIN_TABLES);
        $zip->close();
        foreach ($result['tempFiles'] as $tempFile) {
            @unlink($tempFile);
        }

        $zip->open($zipPath);
        $zip->extractTo($dir);
        $zip->close();
        @unlink($zipPath);

        return ['counts' => $result['counts'], 'skipped' => $result['skipped'], 'assetFiles' => $result['assetFiles']];
    }

    private function json(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
