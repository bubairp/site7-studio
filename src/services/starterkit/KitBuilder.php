<?php

namespace site7\studio\services\starterkit;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use site7\studio\services\sitekit\SiteKitContent;
use site7\studio\services\template\TemplateBuilder;
use site7\studio\services\theme\ThemeBuilder;
use site7\studio\Site7Studio;

/**
 * Builds a Library Starter Kit (format v2, docs/51_LIBRARY_STARTER_KIT.md):
 * the Theme, every page's Template package, and the content no page owns,
 * as a Library package in packages/<name>-starter-kit/:
 *
 *   manifest.json      type "starter-kit"; requires the Theme and the Templates
 *   starter-kit.json   format version, theme, pages, content counts
 *   content/           menus and the HTML sitemap's data (plugin tables),
 *                      and visitor content (reviews) as demo content; its
 *                      links.json connects reviews to their pages
 *
 * Separate from the blueprint Starter Kit system (docs/32), which is untouched.
 */
class KitBuilder extends Component
{
    public const META_FILE = 'starter-kit.json';
    public const FORMAT_VERSION = 2;

    /** Plugin data that belongs to the whole site's pages. */
    public const PLUGIN_TABLES = ['simplerpmenu', 'simplerpmenu_items', 'sitemaps'];

    /**
     * @param bool $buildTemplates rebuild every page's Template package first
     * @return array{handle: string, path: string, meta: array}
     * @throws \Exception
     */
    public function build(string $name, ?string $themeHandle = null, bool $buildTemplates = true, string $version = '1.0.0'): array
    {
        $plugin = Site7Studio::getInstance();
        $themeHandle ??= TemplateBuilder::libraryTheme();
        $handle = self::handleFor($name);

        if ($buildTemplates) {
            $built = (new TemplateBuilder())->buildAll($themeHandle);
            if ($built['errors']) {
                throw new \Exception('Some pages could not be built: ' . implode('; ', array_slice($built['errors'], 0, 5)));
            }
        }

        $templates = [];
        $pages = [];
        foreach (glob(dirname(Craft::getAlias('@site7/studio')) . '/packages/' . TemplateBuilder::HANDLE_PREFIX . '*/' . TemplateBuilder::META_FILE) ?: [] as $file) {
            $manifest = json_decode((string)@file_get_contents(dirname($file) . '/manifest.json'), true);
            if (in_array($themeHandle, $manifest['requires']['themes'] ?? [], true)) {
                $templates[] = $manifest['handle'];
                $pages[] = json_decode((string)file_get_contents($file), true)['uri'] ?? null;
            }
        }
        sort($templates);
        if (!$templates) {
            throw new \Exception("The Library has no Template packages for the Theme '{$themeHandle}'.");
        }

        $projectConfig = Craft::$app->getProjectConfig();
        $demoSections = ThemeBuilder::visitorSectionUids($projectConfig->get('plugins.guest-entries.settings') ?? []);

        $dir = dirname(Craft::getAlias('@site7/studio')) . "/packages/{$handle}";
        if (is_dir($dir)) {
            FileHelper::removeDirectory($dir);
        }
        FileHelper::createDirectory($dir);

        try {
            $content = $this->exportContent($dir, array_keys($demoSections));
        } catch (\Throwable $e) {
            FileHelper::removeDirectory($dir);
            throw $e;
        }

        $meta = [
            'formatVersion' => self::FORMAT_VERSION,
            'craftVersion' => Craft::$app->getVersion(),
            'theme' => $themeHandle,
            'templates' => count($templates),
            'pages' => array_values(array_filter($pages, fn($uri) => $uri !== null)),
            'demoSections' => array_values(array_map(fn($uid) => $projectConfig->get("sections.{$uid}.handle"), array_keys($demoSections))),
            'pluginTables' => self::PLUGIN_TABLES,
            'content' => $content,
            'builtAt' => date(DATE_ATOM),
        ];
        file_put_contents("{$dir}/" . self::META_FILE, $this->json($meta));

        file_put_contents("{$dir}/manifest.json", $this->json([
            'schemaVersion' => '1',
            'handle' => $handle,
            'name' => $name,
            'type' => 'starter-kit',
            'version' => $version,
            'author' => Craft::$app->getUser()->getIdentity()?->friendlyName ?? 'Site7',
            'description' => "The whole {$name} site on a fresh Craft install: the {$themeHandle} Theme, " . count($templates) . ' pages with their blocks, menus and demo content.',
            'requires' => ['themes' => [$themeHandle], 'templates' => $templates],
            'pricingType' => 'free',
        ]));
        file_put_contents("{$dir}/README.md", "# {$name}\n\nLibrary Starter Kit built with `site7-studio/starter-kit/build`. See docs/51_LIBRARY_STARTER_KIT.md.\n");

        $plugin->packageManager->discoverPackages();
        if ($record = $plugin->packageManager->getPackageByHandle($handle)) {
            $record->creatorId = Craft::$app->getUser()->getId()
                ?? \craft\elements\User::find()->admin(true)->status(null)->ids()[0] ?? null;
            $record->save(false);
        }

        return ['handle' => $handle, 'path' => $dir, 'meta' => $meta];
    }

    /** "RP Craft" => rp-craft-starter-kit */
    public static function handleFor(string $name): string
    {
        $handle = StringHelper::toKebabCase($name);

        return str_ends_with($handle, '-starter-kit') ? $handle : "{$handle}-starter-kit";
    }

    private function exportContent(string $dir, array $sectionUids): array
    {
        $zipPath = Craft::$app->getRuntimePath() . '/site7-kit-content-' . StringHelper::randomString(6) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $result = (new SiteKitContent())->export($zip, $sectionUids, self::PLUGIN_TABLES);
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
