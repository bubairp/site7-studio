<?php

namespace site7\studio\services\support;

use site7\studio\Site7Studio;

/**
 * Which Theme a design needs (docs/60 §5): the same shape as StudioVersion, for
 * the site's installed Theme package instead of this plugin.
 *
 * A design is built by the site's frontend/ tooling, which reaches the site with
 * the Theme. A design that relies on newer tooling names the lowest Theme with it
 * (minimumThemeVersion in its design.json); install, update and the Updates screen
 * refuse it on an older Theme, so a deploy never skips building it.
 */
class ThemeVersion
{
    /**
     * The Theme release whose frontend/ builds a design laid out in frontend/src
     * (and runs its js/<handle>.js): vite.design.mjs and build-designs.mjs before
     * it only look in src/. Raised by hand when designs start needing something new.
     */
    public const DESIGN_FRONTEND_LAYOUT = '1.9.0';

    /** The installed Library Theme's version, or null on a site without one. */
    public static function installed(): ?string
    {
        foreach (Site7Studio::getInstance()->packageManager->getAllPackages() as $record) {
            if ($record->type === 'theme' && in_array($record->status, ['installed', 'enabled'], true)) {
                return (string)$record->version;
            }
        }

        return null;
    }

    /**
     * Whether $current (default: the installed Theme) is at least $minimum. No
     * minimum is always satisfied, and so is a site without a Library Theme: there
     * is nothing to compare, and such a site builds with its own frontend/.
     */
    public static function satisfies(?string $minimum, ?string $current = null): bool
    {
        $minimum = trim((string)$minimum);
        $current ??= self::installed();

        return $minimum === '' || $current === null
            || version_compare(StudioVersion::parse($current), StudioVersion::parse($minimum), '>=');
    }

    /** Why a design can't be used here, for errors and the Updates screen. */
    public static function refusal(string $package, string $minimum, ?string $current = null): string
    {
        $current ??= self::installed();

        return "{$package} needs the Theme {$minimum} or newer; this site has " . ($current ?? 'none') . '. Update the Theme first.';
    }
}
