<?php

namespace site7\studio\services\support;

use site7\studio\Site7Studio;

/**
 * Which Site7 Studio a package needs (docs/52 §5).
 *
 * Craft reports this plugin's version as the Git branch it was installed
 * from (dev-main), so the release number is a constant in the code,
 * Site7Studio::RELEASE, raised by hand when packages start needing
 * something new. It goes to Commerce24 on every request (HEADER), and a
 * package's manifest names the lowest one it works with
 * (minimumSite7Version). Commerce24 never offers a site a version its
 * Site7 Studio is too old for; this class is the same check on the site,
 * for archives that arrive some other way.
 */
class StudioVersion
{
    public const HEADER = 'X-Site7-Studio-Version';

    /**
     * The release that serves the base theme from the site7/theme package
     * (docs/60 §3): a Theme or kit built on it needs at least this one, or
     * the site loses the core templates the update removes.
     */
    public const THEME_PACKAGE = '1.1.0';

    /**
     * The release that checks a design's minimumThemeVersion (ThemeVersion,
     * docs/60 §4b): a design that has one needs at least this one, so Commerce24
     * never offers it to a Site7 Studio that would install it on too old a Theme.
     */
    public const DESIGN_THEME_GATE = '1.2.0';

    /** A version string as MAJOR.MINOR.PATCH; anything else (missing, a branch name) is 0.0.0. */
    public static function parse(mixed $version): string
    {
        return is_string($version) && preg_match('/^\d+\.\d+\.\d+$/', trim($version)) ? trim($version) : '0.0.0';
    }

    /** Whether $current (default: this plugin) is at least $minimum; no minimum is always satisfied. */
    public static function satisfies(?string $minimum, ?string $current = null): bool
    {
        $minimum = trim((string)$minimum);

        return $minimum === '' || version_compare(self::parse($current ?? Site7Studio::RELEASE), self::parse($minimum), '>=');
    }

    /** Why a package can't be used here, for errors and the Updates screen. */
    public static function refusal(string $package, string $minimum): string
    {
        return "{$package} needs Site7 Studio {$minimum} or newer; this site has " . Site7Studio::RELEASE . '. Update Site7 Studio first (composer update site7/studio).';
    }
}
