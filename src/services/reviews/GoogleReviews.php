<?php

namespace site7\studio\services\reviews;

use Craft;
use craft\helpers\App;
use GuzzleHttp\Client;
use site7\studio\Site7Studio;

/**
 * A business's Google rating and reviews for the Reviews block (docs/61), read from the
 * Places API (New) with the site's key and Place ID. Never stored as content: the place is
 * cached for CACHE_TTL, and a copy kept for STALE_TTL is shown while Google can't be
 * reached. Google returns the rating, the total count and at most five reviews.
 */
class GoogleReviews
{
    public const CACHE_TTL = 43200;   // 12 hours
    public const STALE_TTL = 604800;  // 7 days
    private const ENDPOINT = 'https://places.googleapis.com/v1/';
    private const PLACE_FIELDS = 'id,displayName,rating,userRatingCount,googleMapsUri,reviews';
    private const SEARCH_FIELDS = 'places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount';

    private ?string $apiKey;
    private ?string $placeId;

    /**
     * The key and Place ID: given, else the plugin settings, else GOOGLE_PLACES_API_KEY and
     * GOOGLE_PLACE_ID from .env directly (a site's config/site7-studio.php may not map them).
     */
    public function __construct(?string $apiKey = null, ?string $placeId = null)
    {
        $settings = Site7Studio::getInstance()?->getSettings();
        $apiKey ??= ($settings?->googlePlacesApiKey ?: null) ?? (App::env('GOOGLE_PLACES_API_KEY') ?: null);
        $placeId ??= ($settings?->googlePlaceId ?: null) ?? (App::env('GOOGLE_PLACE_ID') ?: null);
        $this->apiKey = trim((string)App::parseEnv((string)$apiKey)) ?: null;
        $this->placeId = trim((string)App::parseEnv((string)$placeId)) ?: null;
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== null;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->placeId !== null;
    }

    /**
     * The place, normalised (normalise()), from the cache when it's there; null when
     * unconfigured, or when Google fails and no recent copy is left.
     */
    public function place(?string $language = null, bool $fresh = false): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        $language ??= self::language(Craft::$app->getSites()->getCurrentSite()->language);
        $key = 'site7-reviews-google-' . md5($this->placeId . '|' . $language);
        $cache = Craft::$app->getCache();
        if (!$fresh && is_array($cached = $cache->get($key))) {
            return $cached;
        }
        try {
            $place = self::normalise($this->request('GET', 'places/' . rawurlencode($this->placeId), self::PLACE_FIELDS, ['languageCode' => $language]));
            $cache->set($key, $place, self::CACHE_TTL);
            $cache->set("{$key}-stale", $place, self::STALE_TTL);
            return $place;
        } catch (\Throwable $e) {
            Craft::warning("Google reviews: {$e->getMessage()}", 'site7-studio');
            $stale = $cache->get("{$key}-stale");
            return is_array($stale) ? $stale : null;
        }
    }

    /**
     * Places matching a name (Text Search), to find a business's Place ID:
     * [{id, name, address, rating, count}]. Needs only the key.
     */
    public function search(string $query): array
    {
        if (!$this->hasKey()) {
            throw new \Exception('No Google Places API key: set GOOGLE_PLACES_API_KEY in .env.');
        }
        $data = $this->request('POST', 'places:searchText', self::SEARCH_FIELDS, [], ['textQuery' => $query]);

        return array_map(fn(array $place) => [
            'id' => (string)($place['id'] ?? ''),
            'name' => (string)($place['displayName']['text'] ?? ''),
            'address' => (string)($place['formattedAddress'] ?? ''),
            'rating' => isset($place['rating']) ? (float)$place['rating'] : null,
            'count' => (int)($place['userRatingCount'] ?? 0),
        ], $data['places'] ?? []);
    }

    /**
     * A Places API place as the template uses it. Google's text is kept as written;
     * authorUrl and photo are what Google's attribution rules ask to show.
     *
     * @return array{name: string, rating: ?float, count: int, url: ?string, reviews: array}
     */
    public static function normalise(array $place): array
    {
        $reviews = [];
        foreach ($place['reviews'] ?? [] as $review) {
            $text = $review['originalText']['text'] ?? $review['text']['text'] ?? '';
            $reviews[] = [
                'author' => (string)($review['authorAttribution']['displayName'] ?? ''),
                'authorUrl' => $review['authorAttribution']['uri'] ?? null,
                'photo' => $review['authorAttribution']['photoUri'] ?? null,
                'rating' => (float)($review['rating'] ?? 0),
                'text' => (string)$text,
                'time' => $review['publishTime'] ?? null,
                'relativeTime' => $review['relativePublishTimeDescription'] ?? null,
                'url' => $review['googleMapsUri'] ?? null,
            ];
        }

        return [
            'name' => (string)($place['displayName']['text'] ?? ''),
            'rating' => isset($place['rating']) ? (float)$place['rating'] : null,
            'count' => (int)($place['userRatingCount'] ?? 0),
            'url' => $place['googleMapsUri'] ?? null,
            'reviews' => $reviews,
        ];
    }

    /** The reviews to show: at least $minimum stars (null: all), at most $limit, Google's order. */
    public static function select(array $reviews, int $limit, ?float $minimum = null): array
    {
        $kept = array_values(array_filter($reviews, fn(array $review) => $minimum === null || $review['rating'] >= $minimum));

        return array_slice($kept, 0, max(0, $limit));
    }

    /** A site language as Google's languageCode: 'en-GB' -> 'en' (Google picks the region's text itself). */
    public static function language(string $siteLanguage): string
    {
        return strtolower(explode('-', str_replace('_', '-', $siteLanguage))[0] ?: 'en');
    }

    private function request(string $method, string $path, string $fieldMask, array $query = [], ?array $json = null): array
    {
        $client = new Client(['base_uri' => self::ENDPOINT, 'timeout' => 10]);
        $response = $client->request($method, $path, array_filter([
            'headers' => ['X-Goog-Api-Key' => $this->apiKey, 'X-Goog-FieldMask' => $fieldMask],
            'query' => $query ?: null,
            'json' => $json,
        ], fn($value) => $value !== null));

        return json_decode((string)$response->getBody(), true) ?: [];
    }
}
