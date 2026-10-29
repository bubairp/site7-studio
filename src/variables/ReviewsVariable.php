<?php

namespace site7\studio\variables;

use site7\studio\services\reviews\GoogleReviews;

/**
 * craft.site7Reviews: platform reviews for the Reviews block (docs/61).
 *
 *   {% set google = craft.site7Reviews.google(5, 4) %}   (at most 5, 4 stars and up)
 *   google.rating, google.count, google.url, google.name, google.reviews[]
 */
class ReviewsVariable
{
    /** Whether this site has a Google Places API key and Place ID. */
    public function googleConfigured(): bool
    {
        return (new GoogleReviews())->isConfigured();
    }

    /**
     * The Google rating and reviews (GoogleReviews::normalise()), the reviews narrowed to
     * $limit and $minimum stars; null when unconfigured or unavailable.
     */
    public function google(int $limit = 5, ?float $minimum = null): ?array
    {
        $place = (new GoogleReviews())->place();
        if ($place === null) {
            return null;
        }
        $place['reviews'] = GoogleReviews::select($place['reviews'], $limit, $minimum);

        return $place;
    }
}
