<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\services\reviews\GoogleReviews;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Reviews block connections (docs/61).
 *
 *   php craft site7-studio/reviews/test "Business name, City"   # find the Place ID
 *   php craft site7-studio/reviews/test                        # what Google returns for GOOGLE_PLACE_ID
 */
class ReviewsController extends Controller
{
    public function actionTest(string $query = ''): int
    {
        $google = new GoogleReviews();
        if (!$google->hasKey()) {
            $this->stderr("No Google Places API key: set GOOGLE_PLACES_API_KEY in .env.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        if ($query !== '') {
            $places = $google->search($query);
            $this->stdout(count($places) . " place(s) for \"{$query}\":\n");
            foreach ($places as $place) {
                $this->stdout(sprintf("  %s\n    %s · %s ★ from %d reviews\n    GOOGLE_PLACE_ID=%s\n",
                    $place['name'], $place['address'], $place['rating'] ?? '-', $place['count'], $place['id']));
            }
            return ExitCode::OK;
        }

        if (!$google->isConfigured()) {
            $this->stderr("No Place ID: find it with reviews/test \"<business name, city>\", then set GOOGLE_PLACE_ID in .env.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        $place = $google->place(null, true);
        if ($place === null) {
            $this->stderr("Google didn't answer (see storage/logs for the reason).\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf("%s: %s ★ from %d reviews\n%s\n%d review(s) returned:\n",
            $place['name'], $place['rating'] ?? '-', $place['count'], $place['url'] ?? '', count($place['reviews'])));
        foreach ($place['reviews'] as $review) {
            $this->stdout(sprintf("  %s ★  %s (%s)\n     %s\n", $review['rating'], $review['author'], $review['relativeTime'] ?? $review['time'] ?? '',
                mb_strimwidth(str_replace("\n", ' ', $review['text']), 0, 110, '…')));
        }

        return ExitCode::OK;
    }
}
