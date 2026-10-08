<?php

namespace site7\studio\services\sitekit;

/**
 * Edits a site's composer.json / composer.lock data (decoded arrays) the way
 * Composer would, so a Theme can carry the author site's Composer files
 * without its own Site7 Studio, and an install can put the target site's
 * Site7 Studio back (docs/49 §2d). Pure functions: no file or network access.
 */
class ComposerFiles
{
    public const SITE7_PACKAGE = 'site7/studio';

    /**
     * composer.lock's content-hash for this composer.json, as
     * Composer\Package\Locker::getContentHash() computes it.
     */
    public static function contentHash(array $composerJson): string
    {
        $relevantKeys = ['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra'];
        $relevant = [];
        foreach (array_intersect($relevantKeys, array_keys($composerJson)) as $key) {
            $relevant[$key] = $composerJson[$key];
        }
        if (isset($composerJson['config']['platform'])) {
            $relevant['config']['platform'] = $composerJson['config']['platform'];
        }
        ksort($relevant);

        return md5(json_encode($relevant, 0));
    }

    /**
     * The files without $package: its require line, the repositories it
     * comes from and its locked entry.
     *
     * @return array{0: array, 1: array} [composer.json, composer.lock]
     */
    public static function withoutPackage(array $json, array $lock, string $package): array
    {
        $locked = self::lockedPackage($lock, $package);
        unset($json['require'][$package], $json['require-dev'][$package]);
        if ($locked !== null && isset($json['repositories'])) {
            $json['repositories'] = self::filterRepositories($json['repositories'], fn(array $repository) => !self::provides($repository, $locked));
        }
        foreach (['packages', 'packages-dev'] as $key) {
            if (isset($lock[$key])) {
                $lock[$key] = array_values(array_filter($lock[$key], fn(array $entry) => ($entry['name'] ?? null) !== $package));
            }
        }
        $lock['content-hash'] = self::contentHash($json);

        return [$json, $lock];
    }

    /**
     * The files with $package exactly as the site has it: its require line,
     * the repositories it comes from and its locked entry, from the site's
     * own files. Anything the files already had for $package is replaced.
     *
     * @return array{0: array, 1: array} [composer.json, composer.lock]
     */
    public static function withPackageFrom(array $json, array $lock, array $siteJson, array $siteLock, string $package): array
    {
        [$json, $lock] = self::withoutPackage($json, $lock, $package);
        $locked = self::lockedPackage($siteLock, $package);
        $constraint = $siteJson['require'][$package] ?? null;
        if ($locked === null || $constraint === null) {
            return [$json, $lock];
        }

        $json['require'][$package] = $constraint;
        $repositories = $json['repositories'] ?? [];
        $own = self::filterRepositories($siteJson['repositories'] ?? [], fn(array $repository) => self::provides($repository, $locked));
        foreach ($own as $key => $repository) {
            if (self::isAssociative($repositories)) {
                $repositories[is_string($key) ? $key : 'site7-studio'] = $repository;
            } else {
                $repositories[] = $repository;
            }
        }
        $json['repositories'] = $repositories;
        $lock['packages'][] = $locked;
        usort($lock['packages'], fn(array $a, array $b) => strcmp((string)$a['name'], (string)$b['name']));
        $lock['content-hash'] = self::contentHash($json);

        return [$json, $lock];
    }

    /**
     * Path repositories at these URLs install as copies, not symlinks - a
     * live site's setting - in composer.json and in their locked entries.
     *
     * @param string[] $urls e.g. plugins/rp/ai-chat
     * @return array{0: array, 1: array} [composer.json, composer.lock]
     */
    public static function copyPathRepositories(array $json, array $lock, array $urls): array
    {
        $urls = array_map(fn($url) => trim($url, '/'), $urls);
        foreach ($json['repositories'] ?? [] as $key => $repository) {
            if (is_array($repository) && ($repository['type'] ?? null) === 'path' && in_array(trim((string)($repository['url'] ?? ''), '/'), $urls, true)) {
                $json['repositories'][$key]['options'] = ['symlink' => false] + ($repository['options'] ?? []);
            }
        }
        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $i => $entry) {
                if (($entry['dist']['type'] ?? null) === 'path' && in_array(trim((string)($entry['dist']['url'] ?? ''), '/'), $urls, true)) {
                    $lock[$section][$i]['transport-options'] = ['symlink' => false] + ($entry['transport-options'] ?? []);
                }
            }
        }
        $lock['content-hash'] = self::contentHash($json);

        return [$json, $lock];
    }

    /** The paths of $package's path repositories, from its locked entry (e.g. plugins/site7-studio). */
    public static function packagePaths(array $lock, string $package): array
    {
        $locked = self::lockedPackage($lock, $package);

        return $locked !== null && ($locked['dist']['type'] ?? null) === 'path' ? [trim((string)$locked['dist']['url'], '/')] : [];
    }

    /** Composer's own JSON layout for composer.json / composer.lock. */
    public static function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private static function lockedPackage(array $lock, string $package): ?array
    {
        foreach (['packages', 'packages-dev'] as $key) {
            foreach ($lock[$key] ?? [] as $entry) {
                if (($entry['name'] ?? null) === $package) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /** Whether a repository is where a locked package came from: its path, or its Git/VCS URL. */
    private static function provides(array $repository, array $locked): bool
    {
        $url = trim((string)($repository['url'] ?? ''), '/');
        if ($url === '') {
            return false;
        }
        $type = $repository['type'] ?? null;
        if ($type === 'path') {
            return ($locked['dist']['type'] ?? null) === 'path' && trim((string)$locked['dist']['url'], '/') === $url;
        }
        if (in_array($type, ['vcs', 'git', 'github', 'gitlab', 'bitbucket'], true)) {
            return trim((string)($locked['source']['url'] ?? ''), '/') === $url;
        }

        return false;
    }

    /** Keeps the repositories $keep accepts, as a list or keyed, as they came. */
    private static function filterRepositories(array $repositories, callable $keep): array
    {
        // Entries without a URL ({"packagist.org": false}) never provide a package.
        $kept = array_filter($repositories, fn($repository) => $keep(is_array($repository) ? $repository : []));

        return self::isAssociative($repositories) ? $kept : array_values($kept);
    }

    private static function isAssociative(array $array): bool
    {
        return $array !== [] && array_keys($array) !== range(0, count($array) - 1);
    }
}
