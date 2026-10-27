<?php

namespace site7\studio\services\design;

use craft\helpers\FileHelper;

/**
 * Whether a design's template overrides can take effect on a site (docs/60 §4c).
 *
 * A design replaces a partial such as _layouts/detail/blog.twig; that only shows
 * if the site's template that includes it (entries/channels/blog.twig) still
 * does. A site whose includer is an older or edited copy never renders the
 * design's file. design/build records, per override, the author site's templates
 * that include it by its literal name ("includedBy"); here a site checks that at
 * least one of them, as it renders them (the design's copy first), still does.
 * Overrides reached only through a computed name (blocks through
 * matrix-container, card variants, hero layouts) have no includer and are never
 * checked, so a dynamic include never reads as unused.
 */
class DesignReachability
{
    /** Template file extensions Craft resolves without the extension. */
    private const EXTENSIONS = ['twig', 'html'];

    /** A template's name as other templates refer to it: the path without .twig/.html. */
    public static function templateName(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        return (string)preg_replace('/\.(' . implode('|', self::EXTENSIONS) . ')$/', '', $relativePath);
    }

    /**
     * Whether $source refers to $templateName literally: include/embed/extends/
     * import/from tags, and include()/source() calls, with or without the
     * extension, in single or double quotes. Names built with ~ don't count.
     */
    public static function references(string $source, string $templateName): bool
    {
        $name = preg_quote(self::templateName($templateName), '/');
        $extensions = implode('|', self::EXTENSIONS);

        return (bool)preg_match("/\\b(?:include|embed|extends|import|from|source)\\s*\\(?\\s*(['\"]){$name}(?:\\.(?:{$extensions}))?\\1/", $source);
    }

    /**
     * For each override, the templates under $siteTemplates that include it by
     * its literal name (relative paths, sorted). Overrides nothing includes
     * literally are left out. Several folders are layers, first one wins per
     * path: the site's templates/, then the theme package's.
     *
     * @param string|string[] $siteTemplates
     * @param string[] $overridePaths paths relative to templates/
     * @return array<string, string[]>
     */
    public static function includedBy(string|array $siteTemplates, array $overridePaths): array
    {
        $sources = [];
        foreach ((array)$siteTemplates as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $root = rtrim(str_replace('\\', '/', $dir), '/');
            foreach (FileHelper::findFiles($root, ['only' => array_map(fn($ext) => "*.{$ext}", self::EXTENSIONS)]) as $file) {
                $file = str_replace('\\', '/', $file);
                $sources[ltrim(substr($file, strlen($root)), '/')] ??= (string)file_get_contents($file);
            }
        }
        if (!$overridePaths || !$sources) {
            return [];
        }
        $includedBy = [];
        foreach ($overridePaths as $override) {
            $found = [];
            foreach ($sources as $path => $source) {
                if (self::templateName($path) !== self::templateName($override) && self::references($source, $override)) {
                    $found[] = $path;
                }
            }
            if ($found) {
                sort($found);
                $includedBy[$override] = $found;
            }
        }
        ksort($includedBy);

        return $includedBy;
    }

    /**
     * Overrides none of whose recorded includers still includes them on this
     * site. $source returns an includer's source as the site renders it (the
     * design's copy, else the site's own), or null when the site lacks it.
     *
     * @param array<string, string[]> $includedBy from the design's design.json
     * @param callable(string): ?string $source
     * @return array<string, string[]> override => its includers
     */
    public static function unreachable(array $includedBy, callable $source): array
    {
        $unreachable = [];
        foreach ($includedBy as $override => $includers) {
            $reached = false;
            foreach ((array)$includers as $includer) {
                $content = $source((string)$includer);
                if ($content !== null && self::references($content, (string)$override)) {
                    $reached = true;
                    break;
                }
            }
            if (!$reached && $includers) {
                $unreachable[(string)$override] = array_values((array)$includers);
            }
        }

        return $unreachable;
    }

    /**
     * unreachable() for design $handle on this site: includers resolved the way
     * the design loader renders them, designs/<handle>/templates first, then
     * the site's templates/ and the theme package's.
     *
     * @param array<string, string[]> $includedBy
     * @return array<string, string[]>
     */
    public static function forSite(string $handle, array $includedBy, string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $dirs = ["{$root}/designs/{$handle}/templates", ...DesignLoader::baseTemplateDirs($root)];

        return self::unreachable($includedBy, function(string $includer) use ($dirs): ?string {
            $file = DesignLoader::firstFile($dirs, $includer);

            return $file !== null ? (string)file_get_contents($file) : null;
        });
    }

    /**
     * Template names $source refers to literally (include/embed/extends/import/
     * from tags, include()/source() calls), without extension. A name followed by
     * ~ is the start of a computed name and doesn't count.
     *
     * @return string[]
     */
    public static function literalReferences(string $source): array
    {
        preg_match_all("/\\b(?:include|embed|extends|import|from|source)\\s*\\(?\\s*(['\"])([^'\"~{}]+?)\\1(?!\\s*~)/", $source, $matches);
        $names = array_unique(array_map(fn($name) => self::templateName(trim($name)), $matches[2]));
        sort($names);

        return array_values(array_filter($names, fn($name) => $name !== '' && $name !== '_self'));
    }

    /**
     * Templates a design's own files use that this site doesn't have, e.g. a
     * component added in a newer Theme: rendering those pages would fail.
     *
     * @param array<string, string> $designSources relative path => source of each design template
     * @param callable(string): bool $exists whether a template name resolves (design first, then the site)
     * @return array<string, string[]> missing name => the design files that use it
     */
    public static function missing(array $designSources, callable $exists): array
    {
        $missing = [];
        foreach ($designSources as $path => $source) {
            foreach (self::literalReferences($source) as $name) {
                if (!$exists($name)) {
                    $missing[$name][] = $path;
                }
            }
        }
        ksort($missing);

        return $missing;
    }

    /**
     * missing() for design $handle on this site: a name resolves when the design
     * has the file, or when Craft finds it as a site template (templates/ and the
     * site template roots plugins register).
     *
     * @return array<string, string[]>
     */
    public static function missingForSite(string $handle, string $root): array
    {
        $designTemplates = rtrim(str_replace('\\', '/', $root), '/') . "/designs/{$handle}/templates";
        if (!is_dir($designTemplates)) {
            return [];
        }
        $sources = [];
        foreach (FileHelper::findFiles($designTemplates, ['only' => array_map(fn($ext) => "*.{$ext}", self::EXTENSIONS)]) as $file) {
            $file = str_replace('\\', '/', $file);
            $sources[ltrim(substr($file, strlen($designTemplates)), '/')] = (string)file_get_contents($file);
        }
        $view = \Craft::$app->getView();

        return self::missing($sources, function(string $name) use ($designTemplates, $view): bool {
            foreach (self::EXTENSIONS as $ext) {
                if (is_file("{$designTemplates}/{$name}.{$ext}") || is_file("{$designTemplates}/{$name}/index.{$ext}")) {
                    return true;
                }
            }
            $mode = $view->getTemplateMode();
            try {
                $view->setTemplateMode(\craft\web\View::TEMPLATE_MODE_SITE);
                return $view->resolveTemplate($name) !== false;
            } finally {
                $view->setTemplateMode($mode);
            }
        });
    }

    /** One line per missing template, for the Appearance card and the console. @return string[] */
    public static function missingMessages(string $designName, array $missing): array
    {
        $lines = [];
        foreach ($missing as $name => $users) {
            $lines[] = "{$designName} needs {$name}, which this site doesn't have (used by " . implode(', ', $users)
                . "). Update the Theme first; until then {$designName} can't be activated.";
        }

        return $lines;
    }

    /** One line per unreachable override, for the Appearance card and the console. @return string[] */
    public static function messages(string $designName, array $unreachable): array
    {
        $lines = [];
        foreach ($unreachable as $override => $includers) {
            $lines[] = "{$designName}'s {$override} isn't used on this site: " . implode(', ', $includers)
                . (count($includers) === 1 ? " doesn't" : " don't") . ' include it (an older or edited copy). Update '
                . (count($includers) === 1 ? 'that file' : 'those files') . ' from the Theme, or the page keeps its current look.';
        }

        return $lines;
    }
}
