<?php

namespace site7\studio\services\design;

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
 */
class DesignLoader
{
    /** Query parameter of a signed preview link */
    public const PREVIEW_PARAM = 'site7Design';

    /** The handle meaning "the site's own templates" */
    public const DEFAULT_HANDLE = 'default';

    public static function register(): void
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest() || $request->getIsCpRequest() || $request->getIsActionRequest()) {
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
            return;
        }

        $base = FileHelper::normalizePath(Craft::$app->getPath()->getSiteTemplatesPath());
        $designPath = FileHelper::normalizePath(Craft::getAlias("@root/designs/{$handle}/templates"));
        if (!is_file(Craft::getAlias("@root/designs/{$handle}/design.json")) || $base === $designPath) {
            return; // not a design on this site, or already applied
        }
        // A design that only restyles has no templates/ (a package drops
        // empty folders): it keeps the site's templates and swaps the CSS.
        $hasTemplates = is_dir($designPath);

        if ($preview) {
            self::disableCaches();
        }

        if ($hasTemplates) {
            Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $event) use ($base) {
                $event->roots[''][] = $base;
            });

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
