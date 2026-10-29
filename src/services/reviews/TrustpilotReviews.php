<?php

namespace site7\studio\services\reviews;

use site7\studio\Site7Studio;

/**
 * A business's Trustpilot TrustScore and newest reviews for the Reviews block (docs/61), read
 * from Trustpilot's public API (v1) with the site's own API key and business unit ID (both
 * from the business's Trustpilot account). Caching: ReviewSource.
 */
class TrustpilotReviews extends ReviewSource
{
    private const ENDPOINT = 'https://api.trustpilot.com/v1/';
    private const PER_PAGE = 20;

    private ?string $apiKey;
    private ?string $businessUnitId;

    /** Given, else the plugin settings, else TRUSTPILOT_API_KEY and TRUSTPILOT_BUSINESS_UNIT_ID. */
    public function __construct(?string $apiKey = null, ?string $businessUnitId = null)
    {
        $settings = Site7Studio::getInstance()?->getSettings();
        $this->apiKey = self::value($apiKey, $settings?->trustpilotApiKey, 'TRUSTPILOT_API_KEY');
        $this->businessUnitId = self::value($businessUnitId, $settings?->trustpilotBusinessUnitId, 'TRUSTPILOT_BUSINESS_UNIT_ID');
    }

    public function platform(): string
    {
        return 'trustpilot';
    }

    public static function envNames(): array
    {
        return ['TRUSTPILOT_API_KEY', 'TRUSTPILOT_BUSINESS_UNIT_ID'];
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->businessUnitId !== null;
    }

    protected function cacheId(string $language): string
    {
        return (string)$this->businessUnitId;
    }

    protected function fetch(string $language): array
    {
        $unit = 'business-units/' . rawurlencode((string)$this->businessUnitId);
        $info = $this->getJson(self::ENDPOINT, $unit, ['query' => ['apikey' => $this->apiKey]]);
        $reviews = $this->getJson(self::ENDPOINT, "{$unit}/reviews", ['query' => [
            'apikey' => $this->apiKey, 'perPage' => self::PER_PAGE, 'orderBy' => 'createdat.desc',
        ]]);

        return self::normalise($info, $reviews);
    }

    /** A business unit and its reviews (Trustpilot API v1) in the shared shape (ReviewSource). */
    public static function normalise(array $info, array $reviews): array
    {
        $domain = $info['name']['identifying'] ?? null;
        $list = [];
        foreach ($reviews['reviews'] ?? [] as $review) {
            $id = $review['id'] ?? null;
            $consumer = $review['consumer']['id'] ?? null;
            $list[] = [
                'platform' => 'trustpilot',
                'author' => (string)($review['consumer']['displayName'] ?? ''),
                'authorUrl' => $consumer ? 'https://www.trustpilot.com/users/' . rawurlencode($consumer) : null,
                'photo' => null,
                'rating' => (float)($review['stars'] ?? 0),
                'recommends' => null,
                'title' => ($review['title'] ?? '') !== '' ? (string)$review['title'] : null,
                'text' => (string)($review['text'] ?? ''),
                'time' => $review['createdAt'] ?? null,
                'relativeTime' => null,
                'url' => $id ? 'https://www.trustpilot.com/reviews/' . rawurlencode($id) : null,
            ];
        }

        return [
            'platform' => 'trustpilot',
            'name' => (string)($info['displayName'] ?? ''),
            'rating' => isset($info['score']['trustScore']) ? (float)$info['score']['trustScore'] : null,
            'count' => (int)($info['numberOfReviews']['total'] ?? 0),
            'url' => $domain ? 'https://www.trustpilot.com/review/' . rawurlencode($domain) : null,
            'reviews' => $list,
        ];
    }
}
