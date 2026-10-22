<?php

namespace site7\studio\services\design;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use site7\studio\services\theme\ThemeBuilder;
use site7\studio\Site7Studio;

/**
 * Builds a design package from this site (docs/60) into
 * packages/design-<handle>/:
 *
 *   manifest.json   type "design", requires the Theme it was built on
 *   design.json     the design's own design.json, plus the Theme version, a
 *                   hash of the frontend JS, and the base templates it overrides
 *   files/designs/<handle>/      template overrides, CSS source, design.json, DESIGN.md
 *   files/web/designs/<handle>/  the build (customers never run npm to use it)
 *
 * It never runs npm: build the design first, in frontend/:
 *   DESIGN=<handle> npx vite build --config vite.design.mjs
 */
class DesignBuilder extends Component
{
    public const META_FILE = 'design.json';
    public const FORMAT_VERSION = 1;
    public const HANDLE_PREFIX = 'design-';

    /** Files a design package may not carry: they would run as code or reconfigure the server. */
    private const FORBIDDEN_EXTENSIONS = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'cgi', 'pl', 'sh', 'exe'];
    private const FORBIDDEN_NAMES = ['.htaccess', '.htpasswd', '.user.ini', 'web.config'];

    /**
     * @return array{path: string, meta: array, handle: string}
     * @throws \Exception
     */
    public function build(string $designHandle): array
    {
        if (DesignLoader::validHandle($designHandle) === null) {
            throw new \Exception("'{$designHandle}' isn't a design handle.");
        }
        $root = rtrim(str_replace('\\', '/', Craft::getAlias('@root')), '/');
        $source = "{$root}/designs/{$designHandle}";
        $built = "{$root}/web/designs/{$designHandle}";
        $own = json_decode((string)@file_get_contents("{$source}/" . self::META_FILE), true);
        if (!is_array($own)) {
            throw new \Exception("designs/{$designHandle}/design.json is missing or isn't JSON.");
        }
        if (($own['handle'] ?? $designHandle) !== $designHandle) {
            throw new \Exception("designs/{$designHandle}/design.json says its handle is '{$own['handle']}'.");
        }
        if (!is_dir("{$source}/templates")) {
            throw new \Exception("designs/{$designHandle}/templates is missing.");
        }
        $manifest = "{$built}/.vite/manifest.json";
        if (!is_file($manifest)) {
            throw new \Exception("No build in web/designs/{$designHandle}. In frontend/: DESIGN={$designHandle} npx vite build --config vite.design.mjs");
        }
        $sourceTime = max(self::newestFile("{$source}/src"), self::newestFile("{$root}/frontend/src"));
        if (filemtime($manifest) < $sourceTime) {
            throw new \Exception("web/designs/{$designHandle} is older than its sources (designs/{$designHandle}/src or frontend/src). Build it again first.");
        }
        $siteManifest = ThemeBuilder::builtFrontendPath($root);
        if ($siteManifest !== null) {
            $missing = array_diff(self::entries("{$root}/{$siteManifest}/.vite/manifest.json"), self::entries($manifest));
            if ($missing) {
                throw new \Exception("The design's build lacks entries the site's templates load: " . implode(', ', $missing) . '. Build it again with vite.design.mjs.');
            }
        }
        $forbidden = [...self::forbiddenFiles($source), ...self::forbiddenFiles($built)];
        if ($forbidden) {
            throw new \Exception('A design may not carry these files: ' . implode(', ', array_slice($forbidden, 0, 10)));
        }

        $handle = self::HANDLE_PREFIX . $designHandle;
        $final = Craft::getAlias('@packages') . "/{$handle}";
        $version = is_file("{$final}/manifest.json") ? ThemeBuilder::existingVersion($final) : (string)($own['version'] ?? '1.0.0');
        $name = ThemeBuilder::existingName($final) ?? (string)($own['name'] ?? ucfirst($designHandle));
        $pricingType = ThemeBuilder::existingPricingType($final);
        $theme = self::installedTheme();

        $dir = ThemeBuilder::startStaging($final);
        $skip = ['filter' => fn(string $path) => in_array(basename($path), ['node_modules', '.git', '.DS_Store'], true) ? false : null];
        FileHelper::copyDirectory($source, "{$dir}/files/designs/{$designHandle}", $skip);
        FileHelper::copyDirectory($built, "{$dir}/files/web/designs/{$designHandle}", $skip);

        $meta = array_merge($own, [
            'formatVersion' => self::FORMAT_VERSION,
            'handle' => $designHandle,
            'name' => $name,
            'theme' => $theme,
            // Built output carries the site's JS as it is now: a Theme whose
            // JS changed afterwards shows up as a different hash (docs/60 §6).
            'frontendHash' => self::frontendHash($root),
            // Base templates this design replaces, with their checksum now, so
            // a later change to the base can be flagged ("doctor").
            'overrides' => self::overrides("{$source}/templates", "{$root}/templates"),
        ]);
        unset($meta['version']);
        file_put_contents("{$dir}/" . self::META_FILE, self::json($meta));
        file_put_contents("{$dir}/manifest.json", self::json(array_filter([
            'schemaVersion' => '1',
            'handle' => $handle,
            'name' => $name,
            'type' => 'design',
            'version' => $version,
            'author' => Craft::$app->getUser()->getIdentity()?->friendlyName ?? 'Site7',
            'description' => (string)($own['description'] ?? "The {$name} design."),
            'pricingType' => $pricingType,
            'requires' => $theme ? ['themes' => [$theme['handle']]] : null,
        ], fn($value) => $value !== null)));
        if (is_file("{$source}/DESIGN.md")) {
            copy("{$source}/DESIGN.md", "{$dir}/README.md");
        }
        $dir = ThemeBuilder::commitStaging($dir, $final);

        $plugin = Site7Studio::getInstance();
        $plugin->packageManager->discoverPackages();
        if ($record = $plugin->packageManager->getPackageByHandle($handle)) {
            $record->creatorId = Craft::$app->getUser()->getId()
                ?? \craft\elements\User::find()->admin(true)->status(null)->ids()[0] ?? null;
            $record->save(false);
        }

        return ['path' => $dir, 'meta' => $meta, 'handle' => $handle];
    }

    /**
     * Files under $dir a design may not carry (relative paths): server-side
     * code, server config, symlinks.
     *
     * @return string[]
     */
    public static function forbiddenFiles(string $dir): array
    {
        $bad = [];
        if (!is_dir($dir)) {
            return $bad;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $dir))), '/');
            if (str_starts_with($relative, 'node_modules/') || $relative === 'node_modules') {
                continue;
            }
            if ($file->isLink() || !self::isAllowedFile($file->getFilename())) {
                $bad[] = $relative;
            }
        }

        return $bad;
    }

    public static function isAllowedFile(string $name): bool
    {
        $lower = strtolower($name);
        if (in_array($lower, self::FORBIDDEN_NAMES, true)) {
            return false;
        }
        foreach (explode('.', $lower) as $i => $part) {
            // shell.php.txt is still served as PHP by some servers
            if ($i > 0 && in_array($part, self::FORBIDDEN_EXTENSIONS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The design's overrides: path relative to templates/ => checksum of the
     * site's own templates/<path> now, or null when the design adds a file
     * the site doesn't have.
     *
     * @return array<string, string|null>
     */
    public static function overrides(string $designTemplates, string $siteTemplates): array
    {
        $overrides = [];
        if (!is_dir($designTemplates)) {
            return $overrides;
        }
        foreach (FileHelper::findFiles($designTemplates) as $file) {
            $relative = ltrim(substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $designTemplates))), '/');
            if (basename($relative) === '.gitkeep') {
                continue;
            }
            $base = "{$siteTemplates}/{$relative}";
            $overrides[$relative] = is_file($base) ? sha1_file($base) : null;
        }
        ksort($overrides);

        return $overrides;
    }

    /** Entry points (src/index.js …) in a Vite manifest. @return string[] */
    public static function entries(string $manifestPath): array
    {
        $manifest = json_decode((string)@file_get_contents($manifestPath), true) ?: [];
        $entries = array_keys(array_filter($manifest, fn($chunk) => is_array($chunk) && !empty($chunk['isEntry'])));
        sort($entries);

        return $entries;
    }

    /** Hash of the frontend JavaScript a design build bundles. */
    public static function frontendHash(string $root): string
    {
        $files = [];
        foreach (["{$root}/frontend/src"] as $dir) {
            if (is_dir($dir)) {
                foreach (FileHelper::findFiles($dir, ['only' => ['*.js', '*.mjs']]) as $file) {
                    $files[ltrim(substr(str_replace('\\', '/', $file), strlen($dir)), '/')] = sha1_file($file);
                }
            }
        }
        ksort($files);

        return sha1(json_encode($files));
    }

    /** @return array{handle: string, version: string}|null the Theme package this site has */
    private static function installedTheme(): ?array
    {
        foreach (Site7Studio::getInstance()->packageManager->getAllPackages() as $record) {
            if ($record->type === 'theme') {
                return ['handle' => $record->handle, 'version' => (string)$record->version];
            }
        }

        return null;
    }

    private static function newestFile(string $dir): int
    {
        $newest = 0;
        if (is_dir($dir)) {
            foreach (FileHelper::findFiles($dir) as $file) {
                $newest = max($newest, (int)filemtime($file));
            }
        }

        return $newest;
    }

    private static function json(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
