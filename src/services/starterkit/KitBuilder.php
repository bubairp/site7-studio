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
    /**
     * @param string[] $sections a pack's Theme sections besides its pages'
     *   own (categories, reviews, data its pages show - docs/49 §2c)
     * @param bool $base with $onlyPages: a base kit (docs/51 §2b) - it sets
     *   up the site with only these pages, and the menus and HTML sitemap
     *   rows that point at them
     * @param string|null $variant use these Template variants where a page has one ("default")
     */
    public function build(string $name, ?string $themeHandle = null, bool $buildTemplates = true, ?string $version = null, ?array $onlyPages = null, array $sections = [], bool $base = false, ?string $variant = null): array
    {
        $isPack = $onlyPages !== null && !$base;
        $buildTemplates = $buildTemplates && $onlyPages === null;
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
        $byEntry = [];
        $justBuilt = array_flip($built['built'] ?? []);
        foreach (glob(dirname(Craft::getAlias('@site7/studio')) . '/packages/' . TemplateBuilder::HANDLE_PREFIX . '*/' . TemplateBuilder::META_FILE) ?: [] as $file) {
            $manifest = json_decode((string)@file_get_contents(dirname($file) . '/manifest.json'), true);
            if (!in_array($themeHandle, $manifest['requires']['themes'] ?? [], true)) {
                continue;
            }
            $meta = json_decode((string)file_get_contents($file), true) ?: [];
            // A variant only when asked for; it's built on its own, so it's never stale here.
            $isVariant = !empty($meta['variant']);
            if (($isVariant && $meta['variant'] !== $variant) || ($onlyPages !== null && !self::inPages($meta, $onlyPages))) {
                continue;
            }
            $stale = !\craft\elements\Entry::find()->uid($meta['entryUid'] ?? '')->status(null)->exists()
                || ($buildTemplates && !$isVariant && !isset($justBuilt[$manifest['handle']]));
            if ($stale) {
                if ($buildTemplates) {
                    FileHelper::removeDirectory(dirname($file));
                    $plugin->packageManager->getPackageByHandle($manifest['handle'])?->delete();
                }
                continue;
            }
            if (isset($byEntry[$meta['entryUid']])) {
                // The variant asked for stands in for its page's package.
                $other = $byEntry[$meta['entryUid']];
                if ($isVariant !== !empty($other['meta']['variant'])) {
                    if (!$isVariant) {
                        continue;
                    }
                } else {
                    throw new \Exception("'{$manifest['handle']}' and '{$other['handle']}' are the same page - rebuild the Templates (drop --templates=0).");
                }
            }
            $byEntry[$meta['entryUid']] = ['handle' => $manifest['handle'], 'meta' => $meta];
        }
        $templates = array_column($byEntry, 'handle');
        $pages = array_map(fn($page) => $page['meta']['uri'] ?? null, array_values($byEntry));
        $pageSections = array_map(fn($page) => (string)($page['meta']['section'] ?? ''), array_values($byEntry));
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
        $name = ThemeBuilder::existingName($final) ?? $name;
        $pricingType = ThemeBuilder::existingPricingType($final);
        $version ??= ThemeBuilder::existingVersion($final);
        $dir = ThemeBuilder::startStaging($final);

        try {
            // A pack has no content of its own: no menus, no demo entries. A
            // base kit: the menus and sitemap rows of its pages, no demo entries.
            if ($isPack) {
                $content = ['counts' => [], 'skipped' => [], 'assetFiles' => []];
            } elseif ($base) {
                $content = (new SiteKitContent())->exportToDir($dir, [], self::PLUGIN_TABLES);
                $pageIds = array_map('intval', (new \craft\db\Query())->select(['id'])->from('{{%elements}}')->where(['uid' => array_keys($byEntry)])->column());
                $content['counts'] = array_merge($content['counts'], self::keepPagesInPluginTables("{$dir}/content", $pageIds, array_filter($pages)));
            } else {
                $content = (new SiteKitContent())->exportToDir($dir, array_keys($demoSections), self::PLUGIN_TABLES);
            }
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
            'base' => $base,
            'sections' => $onlyPages !== null ? array_values(array_unique(array_merge(array_filter($pageSections), $sections))) : null,
            'demoSections' => $onlyPages !== null ? [] : array_values(array_map(fn($uid) => $projectConfig->get("sections.{$uid}.handle"), array_keys($demoSections))),
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
                : ($base ? 'A website to start from on a fresh Craft install: the Theme with its header and footer, ' . count($templates) . ' pages and a menu. Add page packs to it.' : null) ?? "The whole website on a fresh Craft install: the {$themeHandle} Theme, " . count($templates) . ' pages with their blocks, menus and demo content.',
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

    /**
     * A base kit's menus and HTML sitemap: only the items and rows that
     * point at its pages (by entry, or by a link to the page's URI), text
     * items, "#" placeholders, and groups that still have items. Rewrites
     * the exported plugin-tables files.
     *
     * @param int[] $pageIds
     * @param string[] $pageUris
     * @return array<string, int> rows kept per table
     */
    public static function keepPagesInPluginTables(string $contentDir, array $pageIds, array $pageUris): array
    {
        $counts = [];
        $menuFile = "{$contentDir}/plugin-tables/simplerpmenu_items.json";
        if (is_file($menuFile)) {
            $items = self::menuItemsFor(json_decode((string)file_get_contents($menuFile), true) ?: [], $pageIds, $pageUris);
            file_put_contents($menuFile, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $counts['simplerpmenu_items'] = count($items);
        }
        $sitemapFile = "{$contentDir}/plugin-tables/sitemaps.json";
        if (is_file($sitemapFile)) {
            $ids = array_flip($pageIds);
            $rows = array_values(array_filter(json_decode((string)file_get_contents($sitemapFile), true) ?: [], fn($row) => isset($ids[(int)($row['element_id'] ?? 0)])));
            $kept = array_flip(array_map(fn($row) => (int)$row['id'], $rows));
            foreach ($rows as &$row) {
                if (!empty($row['parent']) && !isset($kept[(int)$row['parent']])) {
                    $row['parent'] = null;
                }
            }
            unset($row);
            file_put_contents($sitemapFile, json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $counts['sitemaps'] = count($rows);
        }

        return $counts;
    }

    /**
     * Menu items (simplerpmenu_items rows) a base kit keeps: see keepPagesInPluginTables().
     * Absolute URLs are dropped - on the author site they point at its own pages.
     *
     * @param int[] $pageIds
     * @param string[] $pageUris Craft URIs ("__home__" for the homepage)
     */
    public static function menuItemsFor(array $items, array $pageIds, array $pageUris): array
    {
        $ids = array_flip(array_map('intval', $pageIds));
        $paths = [];
        foreach ($pageUris as $uri) {
            $paths[$uri === '__home__' ? '/' : '/' . trim($uri, '/')] = true;
        }
        $byId = [];
        foreach ($items as $item) {
            $byId[(int)$item['id']] = $item;
        }
        $children = [];
        foreach ($items as $item) {
            $children[(int)($item['parent_id'] ?? 0)][] = (int)$item['id'];
        }

        // true: keep, false: drop, null: a group or text item - kept while it has items, or if it never had any.
        $own = [];
        foreach ($byId as $id => $item) {
            $url = trim((string)($item['custom_url'] ?? ''));
            if (($item['entry_id'] ?? null) !== null && $item['entry_id'] !== '') {
                $own[$id] = isset($ids[(int)$item['entry_id']]);
            } elseif ($url === '') {
                $own[$id] = null;
            } elseif ($url === '#') {
                $own[$id] = true;
            } elseif (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || str_starts_with($url, '//')) {
                $own[$id] = false;
            } else {
                $own[$id] = isset($paths['/' . trim((string)parse_url($url, PHP_URL_PATH), '/')]);
            }
        }
        $keep = function(int $id) use (&$keep, $own, $children): bool {
            if ($own[$id] === false) {
                return false;
            }
            $kids = $children[$id] ?? [];
            $keptKids = array_filter($kids, fn($child) => $keep($child));
            return $own[$id] === true || $keptKids || !$kids;
        };
        $kept = [];
        foreach (array_keys($byId) as $id) {
            // Under a dropped parent (one that's in the menu), the item goes too.
            $ok = $keep($id);
            for ($parent = (int)($byId[$id]['parent_id'] ?? 0); $ok && $parent && isset($byId[$parent]); $parent = (int)($byId[$parent]['parent_id'] ?? 0)) {
                $ok = $keep($parent);
            }
            if ($ok) {
                $kept[] = $byId[$id];
            }
        }

        return $kept;
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
