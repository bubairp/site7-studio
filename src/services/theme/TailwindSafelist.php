<?php

namespace site7\studio\services\theme;

use Craft;
use craft\helpers\FileHelper;

/**
 * Keeps Tailwind's classes for Library blocks in a site's frontend build
 * (docs/59_TAILWIND_SAFELIST.md).
 *
 * The Theme's Tailwind v4 entry (frontend/src/css/app.css) scans the site's
 * own templates (`@source '../../../templates/**\/*.twig'`), so a customer
 * who runs `npm run build` gets only the classes of the blocks installed at
 * that moment - a block installed later shows partly unstyled. FILE, next to
 * the entry and added to it with an `@source` line, lists the classes of
 * every Library block:
 * - ThemeBuilder writes it from all Library Section packages of the author
 *   site, so the Theme ships it;
 * - installing or updating a Section package merges its classes in
 *   (PackageManagerService::installPackage(), LibraryUpdater::applySection());
 * - a Theme update merges the new Theme's list into the site's
 *   (ThemeUpdater::applyFiles()), never replaces it.
 *
 * It's a generated file owned by no package, so it's never an installed-file
 * baseline (site7_installed_files): update/conflict checks don't see it.
 *
 * Classes built in Twig (`lg:grid-cols-{{ n }}`) can't be read from a
 * template and are skipped: blocks write full class names (docs/59 §4).
 */
class TailwindSafelist
{
    public const FILE = 'site7-library.safelist';

    /** The line the Tailwind entry gets; same on the author and customer side. */
    public const SOURCE_LINE = "@source './" . self::FILE . "';";

    /** Utility-looking classes, for the "not in the built CSS" check (a stylesheet's own class names aren't utilities). */
    private const UTILITY = '/(:|\[|^-?(p|m|px|py|pt|pb|pl|pr|mx|my|mt|mb|ml|mr|w|h|gap|text|bg|flex|grid|col|row|rounded|border|shadow|top|left|right|bottom|inset|z|max|min|leading|tracking|font|items|justify|self|order|opacity|translate|scale|rotate|overflow|object|aspect|space|divide|line-clamp)-)/';

    /** A class name Tailwind could read: no Twig, quotes, braces or spaces. */
    private const CLASS_TOKEN = '/^-?!?[A-Za-z0-9@\[\]][A-Za-z0-9_:\/.\-\[\]()%#,!&=>*~+]*$/';

    // ------------------------------------------------------------- pure

    /**
     * The class names a template writes literally: in class="..." / class='...'
     * attributes, and in quoted strings inside Twig tags
     * (`{% set classes = 'p-4 md:p-8' %}`, `{{ x ? 'text-white' : '' }}`).
     * A name glued to a Twig expression (`lg:grid-cols-{{ n }}`) is dropped.
     *
     * @return string[] sorted, unique
     */
    public static function extractClasses(string $twig): array
    {
        $classes = [];
        $tokens = function(string $text) use (&$classes) {
            foreach (preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $token) {
                if (strlen($token) <= 120 && preg_match(self::CLASS_TOKEN, $token) && !str_contains($token, "\x01")) {
                    $classes[$token] = true;
                }
            }
        };

        // Quoted strings inside Twig tags.
        preg_match_all('/\{\{.*?\}\}|\{%.*?%\}/s', $twig, $tags);
        foreach ($tags[0] as $tag) {
            preg_match_all('/\'([^\'\\\\]*)\'|"([^"\\\\]*)"/', $tag, $literals, PREG_SET_ORDER);
            foreach ($literals as $literal) {
                $tokens($literal[1] !== '' ? $literal[1] : ($literal[2] ?? ''));
            }
        }

        // class attributes, with Twig removed: an expression becomes a marker
        // that spoils the name it touches; a statement or comment separates.
        $plain = preg_replace('/\{\{.*?\}\}/s', "\x01", $twig);
        $plain = preg_replace('/\{%.*?%\}|\{#.*?#\}/s', ' ', $plain);
        preg_match_all('/\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $plain, $attributes, PREG_SET_ORDER);
        foreach ($attributes as $attribute) {
            $tokens(($attribute[1] ?? '') !== '' ? $attribute[1] : ($attribute[2] ?? ''));
        }

        $classes = array_keys($classes);
        sort($classes, SORT_STRING);

        return $classes;
    }

    /** Only the classes from class attributes that look like Tailwind utilities. */
    public static function utilityClasses(string $twig): array
    {
        $plain = preg_replace('/\{\{.*?\}\}/s', "\x01", $twig);
        $plain = preg_replace('/\{%.*?%\}|\{#.*?#\}/s', ' ', $plain);
        preg_match_all('/\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $plain, $attributes, PREG_SET_ORDER);
        $classes = [];
        foreach ($attributes as $attribute) {
            foreach (preg_split('/\s+/', ($attribute[1] ?? '') !== '' ? $attribute[1] : ($attribute[2] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $token) {
                if (!str_contains($token, "\x01") && preg_match(self::CLASS_TOKEN, $token) && preg_match(self::UTILITY, $token)) {
                    $classes[$token] = true;
                }
            }
        }
        $classes = array_keys($classes);
        sort($classes, SORT_STRING);

        return $classes;
    }

    /**
     * $css with SOURCE_LINE added once: after its last `@source` line, else
     * after its `@import 'tailwindcss'` line. Deterministic, so the author's
     * Theme and a customer site end up with the same file (a Theme update
     * then sees it as already applied).
     */
    public static function withSourceLine(string $css): string
    {
        if (preg_match('/^\s*@source\s+[\'"]\.\/' . preg_quote(self::FILE, '/') . '[\'"]/m', $css)) {
            return $css;
        }
        $lines = preg_split('/(?<=\n)/', $css);
        $at = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*@source\b/', $line)) {
                $at = $i;
            }
        }
        if ($at === null) {
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*@import\s+[\'"]tailwindcss[\'"]/', $line)) {
                    $at = $i;
                    break;
                }
            }
        }
        if ($at === null) {
            return rtrim($css, "\n") . "\n" . self::SOURCE_LINE . "\n";
        }
        if (!str_ends_with($lines[$at], "\n")) {
            $lines[$at] .= "\n";
        }
        array_splice($lines, $at + 1, 0, [self::SOURCE_LINE . "\n"]);

        return implode('', $lines);
    }

    /** The class names a safelist file holds (its comment lines aside). */
    public static function parseList(string $contents): array
    {
        $classes = [];
        foreach (preg_split('/\R/', $contents) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $classes[$line] = true;
            }
        }

        return array_keys($classes);
    }

    /** A safelist file's contents: a header, then one class per line, sorted. */
    public static function render(array $classes): string
    {
        $classes = array_values(array_unique($classes));
        sort($classes, SORT_STRING);

        return "# Generated by Site7 Studio (docs/59): Tailwind classes of the Library blocks,\n"
            . "# read by app.css's @source so `npm run build` keeps them. Classes are only added.\n"
            . implode("\n", $classes) . "\n";
    }

    /**
     * Class selectors in built CSS, CSS escapes undone (`.md\:p-4` -> md:p-4).
     *
     * @return array<string, true>
     */
    public static function selectorsFromCss(string $css): array
    {
        $found = [];
        preg_match_all('/\.((?:\\\\[0-9a-fA-F]{1,6} ?|\\\\.|[A-Za-z0-9_-])+)/', $css, $matches);
        foreach ($matches[1] as $selector) {
            $selector = preg_replace_callback('/\\\\([0-9a-fA-F]{1,6}) ?/', fn($m) => mb_chr(hexdec($m[1])), $selector);
            $found[preg_replace('/\\\\(.)/', '$1', $selector)] = true;
        }

        return $found;
    }

    // ------------------------------------------------------------- a site

    /**
     * The Tailwind v4 entry stylesheet under $root/frontend (the first .css
     * importing tailwindcss), or null when the site has no Tailwind frontend.
     */
    public static function entryCss(string $root): ?string
    {
        if (!is_dir("{$root}/frontend")) {
            return null;
        }
        $files = FileHelper::findFiles("{$root}/frontend", [
            'only' => ['*.css'],
            'except' => ['node_modules/', '.git/'],
        ]);
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            if (preg_match('/^\s*@import\s+[\'"]tailwindcss[\'"]/m', (string)file_get_contents($file))) {
                return str_replace('\\', '/', $file);
            }
        }

        return null;
    }

    /**
     * Adds $classes to the safelist next to $root's Tailwind entry, and the
     * entry's @source line if missing. Idempotent; files are only written
     * when they change.
     *
     * @return array{path: string, added: string[]}|null null without a Tailwind frontend
     */
    public static function merge(string $root, array $classes): ?array
    {
        $entry = self::entryCss($root);
        if ($entry === null) {
            return null;
        }
        $css = (string)file_get_contents($entry);
        $withLine = self::withSourceLine($css);
        if ($withLine !== $css) {
            file_put_contents($entry, $withLine);
        }

        $path = dirname($entry) . '/' . self::FILE;
        $current = is_file($path) ? self::parseList((string)file_get_contents($path)) : [];
        $added = array_values(array_diff(array_unique($classes), $current));
        if ($added || !is_file($path)) {
            file_put_contents($path, self::render(array_merge($current, $added)));
        }

        return ['path' => $path, 'added' => $added];
    }

    /**
     * Class selectors of $root's built CSS (the Vite manifest's CSS files),
     * or null when there's no build to check.
     *
     * @return array<string, true>|null
     */
    public static function builtSelectors(string $root): ?array
    {
        $built = ThemeBuilder::builtFrontendPath($root);
        $dir = $built !== null ? "{$root}/{$built}" : null;
        $manifestPath = null;
        foreach ($dir ? ["{$dir}/.vite/manifest.json", "{$dir}/manifest.json"] : [] as $candidate) {
            if (is_file($candidate)) {
                $manifestPath = $candidate;
                break;
            }
        }
        if ($manifestPath === null) {
            return null;
        }
        $files = [];
        foreach ((array)json_decode((string)file_get_contents($manifestPath), true) as $chunk) {
            foreach ((array)($chunk['css'] ?? []) as $css) {
                $files[$css] = true;
            }
            if (str_ends_with((string)($chunk['file'] ?? ''), '.css')) {
                $files[$chunk['file']] = true;
            }
        }
        $selectors = [];
        foreach (array_keys($files) as $file) {
            if (is_file("{$dir}/{$file}")) {
                $selectors += self::selectorsFromCss((string)file_get_contents("{$dir}/{$file}"));
            }
        }

        return $selectors;
    }

    /**
     * The live _blocks template of each Section package on this site.
     *
     * @return array<string, string> package name => template path
     */
    public static function installedBlockTemplates(): array
    {
        $packageManager = \site7\studio\Site7Studio::getInstance()->packageManager;
        $templates = [];
        foreach ($packageManager->getAllPackages() as $record) {
            if ($record->type !== 'section' || !in_array($record->status, ['installed', 'enabled', 'disabled'], true)) {
                continue;
            }
            $block = $packageManager->sectionBlockHandles($record->handle)[0] ?? null;
            $template = $block ? Craft::getAlias('@templates') . "/_blocks/{$block}.twig" : null;
            if ($template && is_file($template)) {
                $templates[$record->name] = $template;
            }
        }

        return $templates;
    }

    /**
     * Per installed block, its utility classes the built CSS lacks; null
     * without a build to check.
     *
     * @return array<string, string[]>|null package name => missing classes (blocks missing none left out)
     */
    public static function missingByBlock(string $root): ?array
    {
        $built = self::builtSelectors($root);
        if ($built === null) {
            return null;
        }
        $missing = [];
        foreach (self::installedBlockTemplates() as $name => $template) {
            $classes = array_values(array_filter(self::utilityClasses((string)file_get_contents($template)), fn($class) => !isset($built[$class])));
            if ($classes) {
                $missing[$name] = $classes;
            }
        }

        return $missing;
    }

    /** The "run npm run build" message for missingByBlock()'s result, or null. */
    public static function rebuildMessage(?array $missingByBlock): ?string
    {
        if (!$missingByBlock) {
            return null;
        }

        return 'Run `npm run build` in frontend/ to apply the styles of ' . implode(', ', array_keys($missingByBlock))
            . ': ' . array_sum(array_map('count', $missingByBlock)) . ' of their classes are not in the built CSS yet.';
    }

    /**
     * The classes of every Section package template in this site's Library.
     *
     * @return string[]
     */
    public static function libraryClasses(): array
    {
        $packageManager = \site7\studio\Site7Studio::getInstance()->packageManager;
        $classes = [];
        foreach ($packageManager->getAllPackages() as $record) {
            $path = $record->type === 'section' ? $packageManager->getPackagePath($record->handle) : null;
            if ($path && is_file("{$path}/template.twig")) {
                array_push($classes, ...self::extractClasses((string)file_get_contents("{$path}/template.twig")));
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * After a Section package's template arrived on this site: merge its
     * classes into the safelist and say whether the built CSS lacks any.
     *
     * @return string|null a message asking for `npm run build`, or null
     */
    public static function afterBlockInstalled(string $name, string $templatePath): ?string
    {
        if (!is_file($templatePath)) {
            return null;
        }
        try {
            $root = rtrim(str_replace('\\', '/', (string)Craft::getAlias('@root')), '/');
            $twig = (string)file_get_contents($templatePath);
            if (self::merge($root, self::extractClasses($twig)) === null) {
                return null;
            }
            $built = self::builtSelectors($root);
            if ($built === null) {
                return null;
            }
            $missing = array_values(array_filter(self::utilityClasses($twig), fn($class) => !isset($built[$class])));
            if (!$missing) {
                return null;
            }
            $message = sprintf(
                'Run `npm run build` in frontend/ to apply the styles of %s: %d of its classes are not in the built CSS yet (%s%s).',
                $name, count($missing), implode(', ', array_slice($missing, 0, 6)), count($missing) > 6 ? ', ...' : ''
            );
            Craft::warning($message, 'site7-studio');

            return $message;
        } catch (\Throwable $e) {
            Craft::warning("Could not check the frontend styles of {$name}: " . $e->getMessage(), 'site7-studio');

            return null;
        }
    }
}
