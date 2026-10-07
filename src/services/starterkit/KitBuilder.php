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
    /**
     * @param string[]|null $onlyPages null: the whole site (every page, menus,
     *   demo content). Otherwise a page pack: only the pages of these
     *   sections ("blogs") or these pages ("standardPages/blogs"), with
     *   no menus or demo content - it adds pages to a site (docs/51 §2a).
     *   A pack never rebuilds or removes Template packages.
     */
    public function build(string $name, ?string $themeHandle = null, bool $buildTemplates = true, ?string $version = null, ?array $onlyPages = null): array
    {
        $isPack = $onlyPages !== null;
        $buildTemplates = $buildTemplates && !$isPack;
        $plugin = Site7Studio::getInstance();
        $themeHandle ??= TemplateBuilder::libraryTheme();
        $handle = self::handleFor($name);

        if ($buildTemplates) {
            $built = (new TemplateBuilder())->buildAll($themeHandle);
            if ($built['errors']) {
                throw new \Exception('Some pages could not be built: ' . implode('; ', array_slice($built['errors'], 0, 5)));
            }
        }

        // Only pages that still exist here: a Template package left behind by
        // a page deleted (or re-slugged) on this site must not ship again.
        // One package per page, too: a re-slugged page was just built under
        // its new handle, and its old package must go.
        $templates = [];
        $pages = [];
        $byEntry = [];
        $justBuilt = array_flip($built['built'] ?? []);
        foreach (glob(dirname(Craft::getAlias('@site7/studio')) . '/packages/' . TemplateBuilder::HANDLE_PREFIX . '*/' . TemplateBuilder::META_FILE) ?: [] as $file) {
            $manifest = json_decode((string)@file_get_contents(dirname($file) . '/manifest.json'), true);
            if (!in_array($themeHandle, $manifest['requires']['themes'] ?? [], true)) {
                continue;
            }
            $meta = json_decode((string)file_get_contents($file), true) ?: [];
            if ($isPack && !self::inPages($meta, $onlyPages)) {
                continue;
            }
            $stale = !\craft\elements\Entry::find()->uid($meta['entryUid'] ?? '')->status(null)->exists()
                || ($buildTemplates && !isset($justBuilt[$manifest['handle']]));
            if ($stale) {
                if ($buildTemplates) {
                    FileHelper::removeDirectory(dirname($file));
                    $plugin->packageManager->getPackageByHandle($manifest['handle'])?->delete();
                }
                continue;
            }
            if (isset($byEntry[$meta['entryUid']])) {
                throw new \Exception("'{$manifest['handle']}' and '{$byEntry[$meta['entryUid']]}' are the same page - rebuild the Templates (drop --templates=0).");
            }
            $byEntry[$meta['entryUid']] = $manifest['handle'];
            $templates[] = $manifest['handle'];
            $pages[] = $meta['uri'] ?? null;
        }
        sort($templates);
        if (!$templates) {
            throw new \Exception($isPack
                ? 'No Template packages match ' . implode(', ', $onlyPages) . " for the Theme '{$themeHandle}'."
                : "The Library has no Template packages for the Theme '{$themeHandle}'.");
        }

        $projectConfig = Craft::$app->getProjectConfig();
        $demoSections = ThemeBuilder::visitorSectionUids($projectConfig->get('plugins.guest-entries.settings') ?? []);

        // Built next to the package and swapped in at the end: a failed
        // rebuild leaves the package - with its version and price - as it was.
        $final = dirname(Craft::getAlias('@site7/studio')) . "/packages/{$handle}";
        $pricingType = ThemeBuilder::existingPricingType($final);
        $version ??= ThemeBuilder::existingVersion($final);
        $dir = ThemeBuilder::startStaging($final);

        try {
            // A pack has no content of its own: no menus, no demo entries.
            $content = $isPack
                ? ['counts' => [], 'skipped' => [], 'assetFiles' => []]
                : (new SiteKitContent())->exportToDir($dir, array_keys($demoSections), self::PLUGIN_TABLES);
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
            'pack' => $isPack,
            'demoSections' => $isPack ? [] : array_values(array_map(fn($uid) => $projectConfig->get("sections.{$uid}.handle"), array_keys($demoSections))),
            'pluginTables' => $isPack ? [] : self::PLUGIN_TABLES,
            'content' => $content,
        ];
        file_put_contents("{$dir}/" . self::META_FILE, $this->json($meta));

        file_put_contents("{$dir}/manifest.json", $this->json([
            'schemaVersion' => '1',
            'handle' => $handle,
            'name' => $name,
            'type' => 'starter-kit',
            'version' => $version,
            'author' => Craft::$app->getUser()->getIdentity()?->friendlyName ?? 'Site7',
            'description' => $isPack
                ? count($templates) . " pages for the {$themeHandle} Theme, with their blocks and content. Adds them to a site and leaves its menus as they are."
                : "The whole {$name} site on a fresh Craft install: the {$themeHandle} Theme, " . count($templates) . ' pages with their blocks, menus and demo content.',
            'requires' => ['themes' => [$themeHandle], 'templates' => $templates],
            'pricingType' => $pricingType,
        ]));
        file_put_contents("{$dir}/README.md", "# {$name}\n\nLibrary Starter Kit built with `site7-studio/starter-kit/build`. See docs/51_LIBRARY_STARTER_KIT.md.\n");
        $dir = ThemeBuilder::commitStaging($dir, $final);

        $plugin->packageManager->discoverPackages();
        if ($record = $plugin->packageManager->getPackageByHandle($handle)) {
            $record->creatorId = Craft::$app->getUser()->getId()
                ?? \craft\elements\User::find()->admin(true)->status(null)->ids()[0] ?? null;
            $record->save(false);
        }

        return ['handle' => $handle, 'path' => $dir, 'meta' => $meta];
    }

    /** Whether a Template's page is one of a pack's: its section ("blogs") or the page itself ("standardPages/blogs"). */
    public static function inPages(array $templateMeta, array $pages): bool
    {
        $section = (string)($templateMeta['section'] ?? '');
        $uri = (string)($templateMeta['uri'] ?? '');

        return in_array($section, $pages, true) || in_array("{$section}/{$uri}", $pages, true);
    }

    /** "RP Craft" => rp-craft-starter-kit */
    public static function handleFor(string $name): string
    {
        $handle = StringHelper::toKebabCase($name);

        return str_ends_with($handle, '-starter-kit') ? $handle : "{$handle}-starter-kit";
    }

    private function json(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
