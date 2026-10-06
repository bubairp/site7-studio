<?php

namespace site7\studio\services\support;

use Craft;

/**
 * Which Craft versions a Library Theme, Starter Kit or page installs on
 * (docs/49 §2a): built on X.Y.Z, it installs on any X.* - the Library is
 * rebuilt for each major Craft release. Content rows skip columns the
 * site's tables don't have (SiteKitContent). Full Site Kits stay exact:
 * they swap the whole database.
 */
final class CraftVersion
{
    public static function isCompatible(?string $builtOn, ?string $siteVersion = null): bool
    {
        if (!$builtOn) {
            return true;
        }

        return self::major($builtOn) === self::major($siteVersion ?? Craft::$app->getVersion());
    }

    /** "5.x" for "5.10.8.1". */
    public static function range(string $builtOn): string
    {
        return self::major($builtOn) . '.x';
    }

    private static function major(string $version): string
    {
        return explode('.', $version)[0];
    }
}
