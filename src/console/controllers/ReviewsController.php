<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\services\reviews\GoogleReviews;
use site7\studio\variables\ReviewsVariable;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Reviews block connections (docs/61).
 *
 *   php craft site7-studio/reviews/test                          # every platform: connected? what it returns
 *   php craft site7-studio/reviews/test --platform=trustpilot    # one platform
 *   php craft site7-studio/reviews/test "Business name, City"    # Google: find the Place ID
 */
class ReviewsController extends Controller
{
    /** google, trustpilot, facebook or all */
    public string $platform = 'all';

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'platform'];
    }

    public function actionTest(string $query = ''): int
    {
        if ($query !== '') {
            return $this->findGooglePlace($query);
        }
        $platforms = $this->platform === 'all' ? array_keys(ReviewsVariable::PLATFORMS) : [$this->platform];
        if (array_diff($platforms, array_keys(ReviewsVariable::PLATFORMS))) {
            $this->stderr("Unknown platform '{$this->platform}': google, trustpilot, facebook or all.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $answered = 0;
        foreach ($platforms as $platform) {
            $class = ReviewsVariable::PLATFORMS[$platform];
            $source = new $class();
            $this->stdout('== ' . ucfirst($platform) . "\n", Console::BOLD);
            if (!$source->isConfigured()) {
                $this->stdout('  not connected: set ' . implode(' and ', $class::envNames()) . " in .env\n");
                continue;
            }
            $place = $source->place(null, true);
            if ($place === null) {
                $this->stderr("  no answer (see storage/logs for the reason)\n", Console::FG_RED);
                continue;
            }
            $answered++;
            $this->stdout(sprintf("  %s: %s from %d reviews\n  %s\n  %d review(s) returned:\n",
                $place['name'], $place['rating'] !== null ? "{$place['rating']} ★" : 'no star rating', $place['count'], $place['url'] ?? '', count($place['reviews'])));
            foreach (array_slice($place['reviews'], 0, 10) as $review) {
                $score = $review['rating'] !== null ? "{$review['rating']} ★" : ($review['recommends'] ? 'recommends' : "doesn't recommend");
                $this->stdout(sprintf("    %s  %s (%s)\n       %s\n", $score, $review['author'] ?: '(no name)', $review['relativeTime'] ?? $review['time'] ?? '',
                    mb_strimwidth(str_replace("\n", ' ', trim(($review['title'] ? $review['title'] . ': ' : '') . $review['text'])), 0, 110, '…')));
            }
        }

        return $answered ? ExitCode::OK : ExitCode::CONFIG;
    }

    private function findGooglePlace(string $query): int
    {
        $google = new GoogleReviews();
        if (!$google->hasKey()) {
            $this->stderr("No Google Places API key: set GOOGLE_PLACES_API_KEY in .env.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        $places = $google->search($query);
        $this->stdout(count($places) . " place(s) for \"{$query}\":\n");
        foreach ($places as $place) {
            $this->stdout(sprintf("  %s\n    %s · %s ★ from %d reviews\n    GOOGLE_PLACE_ID=%s\n",
                $place['name'], $place['address'], $place['rating'] ?? '-', $place['count'], $place['id']));
        }

        return ExitCode::OK;
    }
}
