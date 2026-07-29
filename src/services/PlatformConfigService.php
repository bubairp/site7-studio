<?php

namespace site7\studio\services;

use craft\base\Component;

/**
 * Real (non-placeholder) Platform Configuration detection - Website Starter
 * Kit System Phase 3, replacing ResourceClassifierService's former private
 * `matchesPlatformSignal()`/`PLATFORM_SIGNAL_WORDS`, which its own docblock
 * called "Placeholder heuristic pending a full PlatformConfigService".
 *
 * The underlying mechanism is still signal-word matching against a field's
 * handle - Craft has no native "this is a site-wide config value, not
 * content" flag to read, and building a real settings-backed Platform
 * Configuration model (the Phase 16 architecture doc's Group A: a plugin
 * Settings-stored theme/color/typography/spacing/custom-code registry) is
 * its own large feature, out of scope here. What changes is that this is now
 * a real, independently-owned, independently-testable service other
 * consumers can call directly - a future Platform Config CP settings screen,
 * or a Phase 6 install step that needs to know what *kind* of platform value
 * a field represents - rather than a private classifier implementation
 * detail with no other caller.
 *
 * Every method is static: this class has zero Craft API dependency (matching
 * a field handle string is pure PHP), so callers that must themselves stay
 * Craft-app-independent for testability - like
 * ResourceClassifierService::classifyField() - can call it without pulling
 * in a live plugin instance via Site7Studio::getInstance(). It's still
 * registered as a Component (`platformConfig`) for consumers that prefer
 * service-location/DI.
 */
class PlatformConfigService extends Component
{
    public const CATEGORY_THEME = 'theme';
    public const CATEGORY_TYPOGRAPHY = 'typography';
    public const CATEGORY_SPACING = 'spacing';
    public const CATEGORY_CUSTOM_CODE = 'custom-code';
    public const CATEGORY_ANIMATION = 'animation';

    /**
     * @var array<string, string[]> category => signal words that indicate
     * it, matched as a case-insensitive substring of a field's handle. Same
     * word list ResourceClassifierService used to hardcode itself
     * (theme/colorpalette/colorlibrary/typography/spacing/codecss/codejs/
     * containerwidth/animationpreset), now grouped by what they actually
     * configure instead of one flat bucket.
     */
    private const SIGNAL_WORDS = [
        self::CATEGORY_THEME => ['theme', 'colorpalette', 'colorlibrary'],
        self::CATEGORY_TYPOGRAPHY => ['typography'],
        self::CATEGORY_SPACING => ['spacing', 'containerwidth'],
        self::CATEGORY_CUSTOM_CODE => ['codecss', 'codejs'],
        self::CATEGORY_ANIMATION => ['animationpreset'],
    ];

    public static function isPlatformConfigField(string $handle): bool
    {
        return self::categoryFor($handle) !== null;
    }

    /**
     * @return string|null One of self::CATEGORY_*, or null if $handle matches no known signal word.
     */
    public static function categoryFor(string $handle): ?string
    {
        $lower = strtolower($handle);
        foreach (self::SIGNAL_WORDS as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($lower, $word)) {
                    return $category;
                }
            }
        }
        return null;
    }

    /**
     * @return array{handle: string, category: string}|null
     */
    public static function describe(string $handle): ?array
    {
        $category = self::categoryFor($handle);
        return $category === null ? null : ['handle' => $handle, 'category' => $category];
    }
}
