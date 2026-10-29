<?php

namespace site7\studio\variables;

use site7\studio\services\reviews\FacebookReviews;
use site7\studio\services\reviews\GoogleReviews;
use site7\studio\services\reviews\ReviewSource;
use site7\studio\services\reviews\TrustpilotReviews;

/**
 * craft.site7Reviews: platform reviews for the Reviews block (docs/61).
 *
 *   {% set reviews = craft.site7Reviews.get('all', 6, 4) %}   (platform, at most 6, 4 stars and up)
 *   reviews.places[] (one per connected platform: platform, name, rating, count, url)
 *   reviews.reviews[] (the reviews to show; 'all': every connected platform, newest first)
 */
class ReviewsVariable
{
    /** platform => its source class */
    public const PLATFORMS = [
        'google' => GoogleReviews::class,
        'trustpilot' => TrustpilotReviews::class,
        'facebook' => FacebookReviews::class,
    ];

    /**
     * The places and reviews for one platform or 'all' (the connected ones); null when none
     * of them is connected or answered.
     */
    public function get(string $platform = 'all', int $limit = 5, ?float $minimum = null): ?array
    {
        $places = [];
        foreach ($this->sources($platform) as $source) {
            if ($source->isConfigured() && ($place = $source->place()) !== null) {
                $places[] = $place;
            }
        }
        if (!$places) {
            return null;
        }
        $reviews = $platform === 'all' ? ReviewSource::merge($places) : $places[0]['reviews'];
        $places = array_map(fn(array $place) => array_diff_key($place, ['reviews' => true]), $places);

        return ['platform' => $platform, 'places' => $places, 'reviews' => ReviewSource::select($reviews, $limit, $minimum)];
    }

    /** Whether the platform (or, for 'all', any platform) is connected on this site. */
    public function configured(string $platform = 'all'): bool
    {
        foreach ($this->sources($platform) as $source) {
            if ($source->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    /** The .env names to set for the platform (for 'all': every platform's). */
    public function envNames(string $platform = 'all'): array
    {
        return array_merge(...array_map(fn(ReviewSource $source) => $source::envNames(), array_values($this->sources($platform))) ?: [[]]);
    }

    /** Google only (the first version of the block): GoogleReviews' place, reviews narrowed. */
    public function google(int $limit = 5, ?float $minimum = null): ?array
    {
        $place = (new GoogleReviews())->place();
        if ($place === null) {
            return null;
        }
        $place['reviews'] = ReviewSource::select($place['reviews'], $limit, $minimum);

        return $place;
    }

    public function googleConfigured(): bool
    {
        return (new GoogleReviews())->isConfigured();
    }

    /** @return ReviewSource[] platform => source */
    private function sources(string $platform): array
    {
        $classes = $platform === 'all' ? self::PLATFORMS : array_intersect_key(self::PLATFORMS, [$platform => true]);

        return array_map(fn(string $class) => new $class(), $classes);
    }
}
