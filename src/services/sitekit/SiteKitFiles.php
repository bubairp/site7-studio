<?php

namespace site7\studio\services\sitekit;

/**
 * Pure helpers behind SiteKitBuilder/SiteKitInstaller (docs/48_FULL_SITE_KIT.md),
 * kept free of Craft so they're unit-testable.
 */
class SiteKitFiles
{
    /** Kit format version, in site-kit.json. */
    public const SCHEMA_VERSION = '1';

    /** Directories copied from the source project root, when present. */
    public const CODE_DIRECTORIES = ['templates', 'modules', 'frontend', 'web/assets'];

    /** Never copied out of a code directory: installed or built output. */
    public const EXCLUDED_DIRECTORY_NAMES = ['node_modules', '.git'];

    /**
     * Files in config/ that are specific to one install (database, licence)
     * and never travel. config/project/ travels separately.
     */
    public const EXCLUDED_CONFIG_FILES = ['db.php', 'license.key'];

    /** Top-level project config keys the target keeps from its own install. */
    public const TARGET_OWNED_CONFIG_KEYS = ['system', 'email'];

    public static function isTravellingConfigFile(string $name): bool
    {
        return !in_array($name, self::EXCLUDED_CONFIG_FILES, true)
            && !str_starts_with($name, 'license.key')
            && $name !== 'project';
    }

    /**
     * Drops plugins listed in project config that aren't actually installed
     * on the source - applying them on the target would abort the whole
     * apply (and Craft then rewrites config/project from the half-applied state).
     *
     * @param string[] $installedHandles
     * @return array{0: array, 1: string[]} [project.yaml data, removed handles]
     */
    public static function removeStalePlugins(array $projectYaml, array $installedHandles): array
    {
        $removed = [];
        foreach (array_keys($projectYaml['plugins'] ?? []) as $handle) {
            if (!in_array($handle, $installedHandles, true)) {
                unset($projectYaml['plugins'][$handle]);
                $removed[] = $handle;
            }
        }

        return [$projectYaml, $removed];
    }

    /**
     * The kit's project.yaml with the target's own system/email settings
     * (site name, edition, mail transport) kept.
     */
    public static function keepTargetSettings(array $kitProjectYaml, array $targetSettings): array
    {
        foreach (self::TARGET_OWNED_CONFIG_KEYS as $key) {
            if (array_key_exists($key, $targetSettings) && $targetSettings[$key] !== null) {
                $kitProjectYaml[$key] = $targetSettings[$key];
            }
        }

        return $kitProjectYaml;
    }

    /**
     * Composer path repositories in a composer.json, as relative paths.
     *
     * @return string[]
     */
    public static function pathRepositories(array $composerJson): array
    {
        $paths = [];
        foreach ($composerJson['repositories'] ?? [] as $repository) {
            if (($repository['type'] ?? null) === 'path' && !empty($repository['url'])) {
                $paths[] = trim((string)$repository['url'], '/');
            }
        }

        return $paths;
    }

    /**
     * Env var names the kit's site uses that the target doesn't define -
     * reported, never written (an empty .env line would override values the
     * host environment provides).
     *
     * @param string[] $kitKeys
     * @param string[] $targetKeys names defined in the target's .env or environment
     * @return string[]
     */
    public static function missingEnvKeys(array $kitKeys, array $targetKeys): array
    {
        return array_values(array_diff($kitKeys, $targetKeys));
    }

    /**
     * Variable names defined in a .env file's contents. Values are ignored.
     *
     * @return string[]
     */
    public static function envKeys(string $dotenv): array
    {
        preg_match_all('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/m', $dotenv, $matches);
        return array_values(array_unique($matches[1]));
    }

    /**
     * Adds every file under $root/$relative to $zip under "files/$relative",
     * skipping EXCLUDED_DIRECTORY_NAMES at any depth.
     */
    public static function addTree(\ZipArchive $zip, string $root, string $relative): int
    {
        $base = rtrim($root, '/') . '/' . $relative;
        if (!is_dir($base)) {
            return 0;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                fn(\SplFileInfo $item) => !($item->isDir() && in_array($item->getFilename(), self::EXCLUDED_DIRECTORY_NAMES, true))
            )
        );

        $count = 0;
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $inside = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($base))), '/');
                $zip->addFile($file->getPathname(), 'files/' . $relative . '/' . $inside);
                $count++;
            }
        }

        return $count;
    }
}
