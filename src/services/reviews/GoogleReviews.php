<?php

namespace site7\studio\services\reviews;

use site7\studio\Site7Studio;

/**
 * A business's Google rating and reviews for the Reviews block (docs/61), read from the
 * Places API (New) with the site's own key and Place ID. Google returns the rating, the total
 * count and at most five reviews. Caching: ReviewSource.
 */
class GoogleReviews extends ReviewSource
{
    private const ENDPOINT = 'https://places.googleapis.com/v1/';
    private const PLACE_FIELDS = 'id,displayName,rating,userRatingCount,googleMapsUri,reviews';
    private const SEARCH_FIELDS = 'places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount';

    private ?string $apiKey;
    private ?string $placeId;

    /** The key and Place ID: given, else the plugin settings, else GOOGLE_PLACES_API_KEY and GOOGLE_PLACE_ID. */
    public function __construct(?string $apiKey = null, ?string $placeId = null)
    {
        $settings = Site7Studio::getInstance()?->getSettings();
        $this->apiKey = self::value($apiKey, $settings?->googlePlacesApiKey, 'GOOGLE_PLACES_API_KEY');
        $this->placeId = self::value($placeId, $settings?->googlePlaceId, 'GOOGLE_PLACE_ID');
    }

    public function platform(): string
    {
        return 'google';
    }

    public static function envNames(): array
    {
        return ['GOOGLE_PLACES_API_KEY', 'GOOGLE_PLACE_ID'];
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== null;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->placeId !== null;
    }

    protected function cacheId(string $language): string
    {
        return $this->placeId . '|' . $language;
    }

    protected function fetch(string $language): array
    {
        return self::normalise($this->getJson(self::ENDPOINT, 'places/' . rawurlencode((string)$this->placeId), [
            'headers' => ['X-Goog-Api-Key' => $this->apiKey, 'X-Goog-FieldMask' => self::PLACE_FIELDS],
            'query' => ['languageCode' => $language],
        ]));
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
        $data = $this->getJson(self::ENDPOINT, 'places:searchText', [
            'method' => 'POST',
            'headers' => ['X-Goog-Api-Key' => $this->apiKey, 'X-Goog-FieldMask' => self::SEARCH_FIELDS],
            'json' => ['textQuery' => $query],
        ]);

        return array_map(fn(array $place) => [
            'id' => (string)($place['id'] ?? ''),
            'name' => (string)($place['displayName']['text'] ?? ''),
            'address' => (string)($place['formattedAddress'] ?? ''),
            'rating' => isset($place['rating']) ? (float)$place['rating'] : null,
            'count' => (int)($place['userRatingCount'] ?? 0),
        ], $data['places'] ?? []);
    }

    /**
     * A Places API place in the shared shape (ReviewSource). Google's text is kept as written
     * (originalText, not the translation); authorUrl and photo are what Google's attribution
     * rules ask to show.
     */
    public static function normalise(array $place): array
    {
        $reviews = [];
        foreach ($place['reviews'] ?? [] as $review) {
            $reviews[] = [
                'platform' => 'google',
                'author' => (string)($review['authorAttribution']['displayName'] ?? ''),
                'authorUrl' => $review['authorAttribution']['uri'] ?? null,
                'photo' => $review['authorAttribution']['photoUri'] ?? null,
                'rating' => (float)($review['rating'] ?? 0),
                'recommends' => null,
                'title' => null,
                'text' => (string)($review['originalText']['text'] ?? $review['text']['text'] ?? ''),
                'time' => $review['publishTime'] ?? null,
                'relativeTime' => $review['relativePublishTimeDescription'] ?? null,
                'url' => $review['googleMapsUri'] ?? null,
            ];
        }

        return [
            'platform' => 'google',
            'name' => (string)($place['displayName']['text'] ?? ''),
            'rating' => isset($place['rating']) ? (float)$place['rating'] : null,
            'count' => (int)($place['userRatingCount'] ?? 0),
            'url' => $place['googleMapsUri'] ?? null,
            'reviews' => $reviews,
        ];
    }
}
