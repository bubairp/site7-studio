<?php

namespace site7\studio\services\theme;

/**
 * A site's own additions to frontend/src/css/app.css, carried into the
 * Theme's new one (docs/53 §4, Theme update step 1).
 *
 * app.css is the CSS entry: the Theme's imports, then the site's own rules.
 * When the base CSS moved into the site7/theme package, the Theme's app.css
 * changed from importing a few dozen files in frontend/src/css to importing
 * the package, and the update removes those files. A site that added rules
 * to app.css kept its whole old copy as "edited here", still importing the
 * removed files, so its next npm run build failed. When the site only added
 * lines, the update writes the new app.css with those lines in its "this
 * site" part instead; anything else is left to merge by hand.
 */
class CssMerge
{
    public const ENTRY = 'frontend/src/css/app.css';

    /** Comment above the carried-over lines. */
    public const HEADING = "/* Kept from this site's app.css by the Site7 Theme update */";

    /**
     * The lines $live adds to $base, when $live is $base with lines added
     * and none removed or changed; null otherwise, or when an added line is
     * an @import (it must stay above the rules, so it can't be moved safely).
     *
     * @return string[]|null
     */
    public static function additions(string $base, string $live): ?array
    {
        $base = self::lines($base);
        $live = self::lines($live);
        $added = [];
        $i = 0;
        foreach ($live as $line) {
            if ($i < count($base) && $line === $base[$i]) {
                $i++;
                continue;
            }
            $added[] = $line;
        }
        if ($i < count($base)) {
            return null; // a line of the Theme's file was removed or changed
        }
        foreach ($added as $line) {
            if (preg_match('/^\s*@import\b/', $line)) {
                return null;
            }
        }

        return self::trimBlank($added);
    }

    /**
     * $incoming (the new Theme's app.css) with $additions in its "this site"
     * part: before the Tailwind sources section when it has one, else at the end.
     *
     * @param string[] $additions
     */
    public static function merge(string $incoming, array $additions): string
    {
        $incoming = str_replace("\r\n", "\n", $incoming);
        if (!$additions) {
            return $incoming;
        }
        $block = self::HEADING . "\n" . implode("\n", $additions) . "\n\n";
        $lines = explode("\n", $incoming);
        foreach ($lines as $n => $line) {
            if (str_contains($line, 'TAILWIND SOURCES') && $n > 0 && str_starts_with(trim($lines[$n - 1]), '/*')) {
                array_splice($lines, $n - 1, 0, [rtrim($block, "\n"), '']);
                return implode("\n", $lines);
            }
        }

        return rtrim($incoming, "\n") . "\n\n" . $block;
    }

    /** @return string[] */
    private static function lines(string $css): array
    {
        return explode("\n", rtrim(str_replace("\r\n", "\n", $css), "\n"));
    }

    /** @param string[] $lines @return string[] */
    private static function trimBlank(array $lines): array
    {
        while ($lines && trim($lines[0]) === '') {
            array_shift($lines);
        }
        while ($lines && trim(end($lines)) === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }
}
