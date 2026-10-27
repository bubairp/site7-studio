<?php

namespace site7\studio\services\design;

use Composer\InstalledVersions;
use Craft;
use craft\events\RegisterTemplateRootsEvent;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use craft\web\View;
use site7\studio\Site7Studio;
use yii\base\Event;

/**
 * Switchable designs (docs/60_DESIGN_PACKAGE.md).
 *
 * A design is one folder, designs/<handle>/: templates/ holds only the files
 * it changes; its built CSS/JS is in web/designs/<handle>/. With a design
 * active, Craft looks in designs/<handle>/templates first and falls back to
 * templates/ (a site template root without a prefix), so the default design
 * is the site's own templates/, unmoved. The Vite plugin then loads the
 * design's build instead of the site's.
 *
 * Which design a page request renders, first match wins:
 *   1. a signed preview link (?site7Design=…, previewUrl()): works without
 *      Dev Mode or a login, so a client can look before it's activated;
 *   2. ?design=<handle> in Dev Mode;
 *   3. the activeDesign setting (project config; SITE7_DESIGN in .env
 *      overrides it through config/site7-studio.php);
 *   4. the default design.
 * A handle without designs/<handle>/design.json renders the default; a
 * design without a templates/ folder only swaps the CSS/JS.
 *
 * Only page requests: console, CP and action requests keep templates/, since
 * installs and updates write into @templates there.
 *
 * Under both, the base theme: the site7/theme Composer package's templates/
 * (themeTemplatesPath()) is the last site template root on every request, so
 * a template the site doesn't have itself comes from the package. Lookup
 * order: designs/<active>/templates, templates/, the theme package.
 */
class DesignLoader
{
    /** Query parameter of a signed preview link */
    public const PREVIEW_PARAM = 'site7Design';

    /** The handle meaning "the site's own templates" */
    public const DEFAULT_HANDLE = 'default';

    /** Composer package of the base theme */
    public const THEME_PACKAGE = 'site7/theme';

    public static function register(): void
    {
        $theme = self::themeTemplatesPath();
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest() || $request->getIsCpRequest() || $request->getIsActionRequest()) {
            self::registerRoots([$theme]);
            return;
        }

        $settings = Site7Studio::getInstance()?->getSettings();
        [$handle, $preview] = self::resolve(
            self::previewHandle($request->getQueryParam(self::PREVIEW_PARAM)),
            $request->getQueryParam('design'),
            Craft::$app->getConfig()->getGeneral()->devMode,
            $settings->activeDesign ?? null,
        );
        if ($handle === null) {
            // ?design=default while another design is active: the site's own
            // templates, kept out of the caches like any preview
            if ($preview) {
                self::disableCaches();
            }
            self::registerRoots([$theme]);
            return;
        }

        $base = FileHelper::normalizePath(Craft::$app->getPath()->getSiteTemplatesPath());
        $designPath = FileHelper::normalizePath(Craft::getAlias("@root/designs/{$handle}/templates"));
        if (!is_file(Craft::getAlias("@root/designs/{$handle}/design.json")) || $base === $designPath) {
            self::registerRoots([$theme]);
            return; // not a design on this site, or already applied
        }
        // A design that only restyles has no templates/ (a package drops
        // empty folders): it keeps the site's templates and swaps the CSS.
        $hasTemplates = is_dir($designPath);

        if ($preview) {
            self::disableCaches();
        }

        if (!$hasTemplates) {
            self::registerRoots([$theme]);
        } else {
            // templates/ is no longer the templates path, so it becomes a root ahead of the theme
            self::registerRoots([$base, $theme]);

            // Craft resets the templates path to @templates whenever the template
            // mode changes (a plugin rendering a CP template mid-page switches to
            // CP mode and back), so @templates itself points at the design for
            // this request.
            Craft::setAlias('@templates', $designPath);
            Craft::$app->getView()->setTemplatesPath($designPath);
        }

        // The design's own build: the same entries as the site's (templates
        // keep calling craft.vite.script('src/index.js')), its own CSS.
        $manifest = Craft::getAlias("@webroot/designs/{$handle}/.vite/manifest.json");
        if (class_exists(\nystudio107\vite\Vite::class) && isset(\nystudio107\vite\Vite::$plugin) && is_file($manifest)) {
            $vite = \nystudio107\vite\Vite::$plugin->vite;
            $vite->manifestPath = $manifest;
            $vite->serverPublic = "/designs/{$handle}/";
            $vite->useDevServer = false;
        }
    }

    /**
     * The design a request renders and whether it's a preview. Pure, so the
     * precedence above can be tested without a request.
     *
     * @param string|null $previewHandle handle from a valid signed preview link
     * @param mixed $queryHandle ?design= (honoured in Dev Mode only)
     * @param string|null $setting the activeDesign setting
     * @return array{0: ?string, 1: bool} handle (null: the default design), preview
     */
    public static function resolve(?string $previewHandle, mixed $queryHandle, bool $devMode, ?string $setting): array
    {
        if (($handle = self::validHandle($previewHandle)) !== null) {
            return [$handle, true];
        }
        if ($devMode && ($handle = self::validHandle($queryHandle)) !== null) {
            return [$handle, true];
        }
        // ?design=default previews the site's own templates over an active design
        if ($devMode && $queryHandle === self::DEFAULT_HANDLE) {
            return [null, true];
        }

        return [self::validHandle($setting), false];
    }

    /** Preview HTML must never reach (or come from) the template caches, which don't know which design rendered them. */
    private static function disableCaches(): void
    {
        Craft::$app->getConfig()->getGeneral()->enableTemplateCaching = false;
        Craft::$app->getResponse()->getHeaders()->set('Cache-Control', 'no-store');
    }

    /** Adds $bases (null entries skipped) as site template roots, through siteRootsFirst(). */
    private static function registerRoots(array $bases): void
    {
        $bases = array_values(array_filter($bases));
        if (!$bases) {
            return;
        }
        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $event) use ($bases) {
            $event->roots = self::siteRootsFirst($event->roots, $bases);
        });
    }

    /**
     * Site template roots for $bases, in order (the site's own templates/ while
     * a design is active, then the theme package): they become root '', and
     * wherever a plugin registered a root ("payment-gateway") that a base also
     * has as a folder (templates/payment-gateway/), the base's folders go first.
     * Without a design Craft checks templates/ before every plugin root; a
     * design moves templates/ to a root, and the theme is always one, and a
     * plugin's longer key would otherwise win, so its bare templates replaced
     * the site's own (payment pages under Harbor, 2026-10-26). Called after
     * every plugin has registered its roots (register() runs in onInit).
     *
     * @param array<string, string|string[]> $roots
     * @param string|string[] $bases
     * @return array<string, string|string[]>
     */
    public static function siteRootsFirst(array $roots, string|array $bases): array
    {
        $bases = (array)$bases;
        foreach ($roots as $key => $paths) {
            if ($key === '') {
                continue;
            }
            $folders = array_values(array_filter(array_map(fn($base) => "{$base}/{$key}", $bases), 'is_dir'));
            if ($folders) {
                $roots[$key] = array_values(array_unique(array_merge($folders, (array)$paths)));
            }
        }
        $roots[''] = array_merge((array)($roots[''] ?? []), $bases);

        return $roots;
    }

    /**
     * The theme package's templates folder (extra.site7.templates in its
     * composer.json, default templates/), or null on a site without it.
     */
    public static function themeTemplatesPath(): ?string
    {
        $root = self::themePackagePath();
        if ($root === null) {
            return null;
        }
        $composer = json_decode((string)@file_get_contents("{$root}/composer.json"), true) ?: [];
        $path = FileHelper::normalizePath($root . '/' . ($composer['extra']['site7']['templates'] ?? 'templates'));

        return is_dir($path) ? $path : null;
    }

    /** Where Composer installed the theme package, or null on a site without it. */
    public static function themePackagePath(): ?string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled(self::THEME_PACKAGE)) {
            return null;
        }
        $root = InstalledVersions::getInstallPath(self::THEME_PACKAGE);

        return $root !== null && is_dir($root) ? FileHelper::normalizePath($root) : null;
    }

    /** A design handle, or null for anything else (including "default"). */
    public static function validHandle(mixed $handle): ?string
    {
        if (!is_string($handle) || $handle === self::DEFAULT_HANDLE || !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $handle)) {
            return null;
        }

        return $handle;
    }

    /**
     * A link to $url (default: the home page) rendered in design $handle for
     * $ttl seconds, for anyone who has it.
     */
    public static function previewUrl(string $handle, int $ttl = 86400, ?string $url = null): string
    {
        $token = Craft::$app->getSecurity()->hashData(json_encode(['h' => $handle, 'exp' => time() + $ttl]));
        $url ??= UrlHelper::siteUrl();

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query([self::PREVIEW_PARAM => $token]);
    }

    /** The handle in a signed preview token, if the signature holds and it hasn't expired. */
    public static function previewHandle(mixed $token, ?int $now = null): ?string
    {
        if (!is_string($token) || $token === '') {
            return null;
        }
        $data = Craft::$app->getSecurity()->validateData($token);
        $payload = is_string($data) ? json_decode($data, true) : null;
        if (!is_array($payload) || ($payload['exp'] ?? 0) < ($now ?? time())) {
            return null;
        }

        return self::validHandle($payload['h'] ?? null);
    }
}
