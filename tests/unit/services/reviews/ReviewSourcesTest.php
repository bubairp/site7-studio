<?php

namespace site7\studio\tests\unit\services\reviews;

use Codeception\Test\Unit;
use site7\studio\services\reviews\FacebookReviews;
use site7\studio\services\reviews\GoogleReviews;
use site7\studio\services\reviews\ReviewSource;
use site7\studio\services\reviews\TrustpilotReviews;

/**
 * Trustpilot and Facebook in the shared review shape, and what the block shows across
 * platforms (docs/61). No network: the responses are fixtures.
 */
class ReviewSourcesTest extends Unit
{
    protected \UnitTester $tester;

    public function testTrustpilotReadsAsTheTemplateUsesIt(): void
    {
        $place = TrustpilotReviews::normalise(
            ['displayName' => 'Test Shop', 'name' => ['identifying' => 'testshop.example'], 'score' => ['trustScore' => 4.5, 'stars' => 4.5], 'numberOfReviews' => ['total' => 4925]],
            ['reviews' => [
                ['id' => 'r1', 'stars' => 5, 'title' => 'Great', 'text' => 'Fast and friendly.', 'createdAt' => '2026-10-20T09:00:00Z', 'consumer' => ['id' => 'c1', 'displayName' => 'Ana']],
                ['id' => 'r2', 'stars' => 2, 'title' => '', 'text' => 'Slow reply.', 'createdAt' => '2026-10-10T09:00:00Z', 'consumer' => ['displayName' => 'Ben']],
            ]]
        );
        $this->assertSame('trustpilot', $place['platform']);
        $this->assertSame(4.5, $place['rating']);
        $this->assertSame(4925, $place['count']);
        $this->assertSame('https://www.trustpilot.com/review/testshop.example', $place['url']);
        $this->assertSame('Great', $place['reviews'][0]['title']);
        $this->assertNull($place['reviews'][1]['title'], 'an empty title is no title');
        $this->assertSame('https://www.trustpilot.com/reviews/r1', $place['reviews'][0]['url']);
        $this->assertSame('https://www.trustpilot.com/users/c1', $place['reviews'][0]['authorUrl']);
        $this->assertNull($place['reviews'][1]['authorUrl']);
        $this->assertSame(2.0, $place['reviews'][1]['rating']);
    }

    public function testFacebookRecommendationsHaveNoStars(): void
    {
        $place = FacebookReviews::normalise(
            ['name' => 'Test Page', 'link' => 'https://www.facebook.com/testpage/', 'overall_star_rating' => 4.7, 'rating_count' => 31],
            ['data' => [
                ['created_time' => '2026-10-21T08:00:00+0000', 'recommendation_type' => 'positive', 'review_text' => 'Would recommend.', 'reviewer' => ['name' => 'Cara', 'picture' => ['data' => ['url' => 'https://example.com/c.jpg']]]],
                ['created_time' => '2026-10-01T08:00:00+0000', 'recommendation_type' => 'negative', 'review_text' => 'Not for me.'],
                ['created_time' => '2019-05-01T08:00:00+0000', 'rating' => 5, 'review_text' => 'An old star review.'],
            ]]
        );
        $this->assertSame('facebook', $place['platform']);
        $this->assertSame(4.7, $place['rating']);
        $this->assertSame(31, $place['count']);
        $this->assertSame('https://www.facebook.com/testpage/reviews', $place['url']);
        [$first, $second, $old] = $place['reviews'];
        $this->assertTrue($first['recommends']);
        $this->assertNull($first['rating']);
        $this->assertSame('https://example.com/c.jpg', $first['photo']);
        $this->assertFalse($second['recommends']);
        $this->assertSame('', $second['author'], 'Meta often leaves the reviewer out');
        $this->assertSame(5.0, $old['rating']);
        $this->assertNull($old['recommends']);
        $this->assertSame('2026-10-21T08:00:00+00:00', $first['time']);
    }

    public function testARecommendationPassesALowestRatingOnlyWhenItRecommends(): void
    {
        $reviews = [
            ['author' => 'stars 5', 'rating' => 5.0, 'recommends' => null],
            ['author' => 'recommends', 'rating' => null, 'recommends' => true],
            ['author' => 'does not', 'rating' => null, 'recommends' => false],
            ['author' => 'stars 3', 'rating' => 3.0, 'recommends' => null],
        ];
        $this->assertSame(['stars 5', 'recommends'], array_column(ReviewSource::select($reviews, 10, 4), 'author'));
        $this->assertCount(4, ReviewSource::select($reviews, 10));
    }

    public function testAllPlatformsMixNewestFirst(): void
    {
        $merged = ReviewSource::merge([
            ['reviews' => [['author' => 'g old', 'time' => '2026-09-01T00:00:00Z'], ['author' => 'g undated', 'time' => null]]],
            ['reviews' => [['author' => 't new', 'time' => '2026-10-20T00:00:00Z']]],
            ['reviews' => [['author' => 'f mid', 'time' => '2026-10-01T00:00:00+00:00']]],
        ]);
        $this->assertSame(['t new', 'f mid', 'g old', 'g undated'], array_column($merged, 'author'));
        $this->assertSame([], ReviewSource::merge([]));
    }

    public function testEachPlatformNeedsBothItsValues(): void
    {
        $this->assertFalse((new TrustpilotReviews('key', ''))->isConfigured());
        $this->assertTrue((new TrustpilotReviews('key', 'unit'))->isConfigured());
        $this->assertFalse((new FacebookReviews('page', ''))->isConfigured());
        $this->assertTrue((new FacebookReviews('page', 'token'))->isConfigured());
        $this->assertSame(['TRUSTPILOT_API_KEY', 'TRUSTPILOT_BUSINESS_UNIT_ID'], TrustpilotReviews::envNames());
        $this->assertSame(['FACEBOOK_PAGE_ID', 'FACEBOOK_PAGE_TOKEN'], FacebookReviews::envNames());
        $this->assertSame(['GOOGLE_PLACES_API_KEY', 'GOOGLE_PLACE_ID'], GoogleReviews::envNames());
    }
}
