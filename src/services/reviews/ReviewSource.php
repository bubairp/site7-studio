<?php

namespace site7\studio\services\reviews;

use Craft;
use craft\helpers\App;
use GuzzleHttp\Client;

/**
 * One review platform for the Reviews block (docs/61): its connection, fetching and the
 * shared caching. A platform's place is cached for CACHE_TTL; a copy kept for STALE_TTL is
 * returned while the platform can't be reached. Never stored as content.
 *
 * Every platform normalises to the same shape:
 *   place:  {platform, name, rating: ?float (0-5), count: int, url: ?string, reviews[]}
 *   review: {platform, author, authorUrl, photo, rating: ?float, recommends: ?bool,
 *            title, text, time (ISO 8601), relativeTime, url}
 * rating is null where a platform has no stars (a Facebook recommendation: recommends).
 */
abstract class ReviewSource
{
    public const CACHE_TTL = 43200;   // 12 hours
    public const STALE_TTL = 604800;  // 7 days

    /** 'google', 'trustpilot' or 'facebook' */
    abstract public function platform(): string;

    abstract public function isConfigured(): bool;

    /** The .env variables this platform needs, for the admin note and the console. */
    abstract public static function envNames(): array;

    /** What identifies this place's cache entry (account id and language). */
    abstract protected function cacheId(string $language): string;

    /** The place, normalised, straight from the platform; throws when it fails. */
    abstract protected function fetch(string $language): array;

    /**
     * The place from the cache when it's there; null when unconfigured, or when the
     * platform fails and no recent copy is left.
     */
    public function place(?string $language = null, bool $fresh = false): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        $language ??= self::language(Craft::$app->getSites()->getCurrentSite()->language);
        $key = 'site7-reviews-' . $this->platform() . '-' . md5($this->cacheId($language));
        $cache = Craft::$app->getCache();
        if (!$fresh && is_array($cached = $cache->get($key))) {
            return $cached;
        }
        try {
            $place = $this->fetch($language);
            $cache->set($key, $place, static::CACHE_TTL);
            $cache->set("{$key}-stale", $place, static::STALE_TTL);
            return $place;
        } catch (\Throwable $e) {
            Craft::warning(ucfirst($this->platform()) . " reviews: {$e->getMessage()}", 'site7-studio');
            $stale = $cache->get("{$key}-stale");
            return is_array($stale) ? $stale : null;
        }
    }

    /**
     * The reviews to show, in the order given: at least $minimum stars (null: all), at most
     * $limit. A review without stars (a recommendation) passes when it recommends.
     */
    public static function select(array $reviews, int $limit, ?float $minimum = null): array
    {
        $kept = array_values(array_filter($reviews, fn(array $review) => $minimum === null
            || (($review['rating'] ?? null) !== null ? $review['rating'] >= $minimum : ($review['recommends'] ?? false) === true)));

        return array_slice($kept, 0, max(0, $limit));
    }

    /** Several platforms' reviews as one list, newest first (undated last). */
    public static function merge(array $places): array
    {
        $reviews = array_merge(...array_map(fn(array $place) => $place['reviews'] ?? [], array_values($places)) ?: [[]]);
        usort($reviews, fn(array $a, array $b) => strcmp((string)($b['time'] ?? ''), (string)($a['time'] ?? '')));

        return $reviews;
    }

    /** A site language as a two-letter code: 'en-GB' -> 'en'. */
    public static function language(string $siteLanguage): string
    {
        return strtolower(explode('-', str_replace('_', '-', $siteLanguage))[0] ?: 'en');
    }

    /** A connection value: given, else the plugin setting, else the .env variable. */
    protected static function value(?string $given, ?string $setting, string $env): ?string
    {
        $value = $given ?? ($setting ?: null) ?? (App::env($env) ?: null);

        return trim((string)App::parseEnv((string)$value)) ?: null;
    }

    protected function http(string $baseUri): Client
    {
        return new Client(['base_uri' => $baseUri, 'timeout' => 10]);
    }

    protected function getJson(string $baseUri, string $path, array $options = []): array
    {
        $response = $this->http($baseUri)->request($options['method'] ?? 'GET', $path, array_diff_key($options, ['method' => true]));

        return json_decode((string)$response->getBody(), true) ?: [];
    }
}
