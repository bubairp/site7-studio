<?php

namespace site7\studio\tests\unit\services\reviews;

use Codeception\Test\Unit;
use site7\studio\services\reviews\GoogleReviews;

/**
 * Google reviews for the Reviews block (docs/61): a Places API (New) response as the
 * template reads it, and which reviews it shows. No network: the response is a fixture.
 */
class GoogleReviewsTest extends Unit
{
    protected \UnitTester $tester;

    private function place(): array
    {
        return [
            'id' => 'ChIJtest',
            'displayName' => ['text' => 'Test Business', 'languageCode' => 'en'],
            'rating' => 4.6,
            'userRatingCount' => 87,
            'googleMapsUri' => 'https://maps.google.com/?cid=1',
            'reviews' => [
                ['rating' => 5, 'text' => ['text' => 'Translated'], 'originalText' => ['text' => "As written\nby the reviewer"],
                    'relativePublishTimeDescription' => '3 days ago', 'publishTime' => '2026-10-26T10:00:00Z',
                    'authorAttribution' => ['displayName' => 'Reviewer One', 'uri' => 'https://www.google.com/maps/contrib/1', 'photoUri' => 'https://lh3.googleusercontent.com/a'],
                    'googleMapsUri' => 'https://www.google.com/maps/reviews/1'],
                ['rating' => 3, 'text' => ['text' => 'Only translated'], 'authorAttribution' => ['displayName' => 'Reviewer Two']],
                ['rating' => 4, 'authorAttribution' => ['displayName' => 'No text']],
            ],
        ];
    }

    public function testAPlaceReadsAsTheTemplateUsesIt(): void
    {
        $place = GoogleReviews::normalise($this->place());
        $this->assertSame('Test Business', $place['name']);
        $this->assertSame(4.6, $place['rating']);
        $this->assertSame(87, $place['count']);
        $this->assertSame('https://maps.google.com/?cid=1', $place['url']);
        $this->assertCount(3, $place['reviews']);
        $this->assertSame("As written\nby the reviewer", $place['reviews'][0]['text'], 'the original text, not the translation');
        $this->assertSame('Only translated', $place['reviews'][1]['text']);
        $this->assertSame('', $place['reviews'][2]['text']);
        $this->assertSame('https://www.google.com/maps/contrib/1', $place['reviews'][0]['authorUrl']);
        $this->assertSame('3 days ago', $place['reviews'][0]['relativeTime']);
        $this->assertNull($place['reviews'][1]['photo']);
    }

    public function testAPlaceWithoutRatingOrReviews(): void
    {
        $place = GoogleReviews::normalise(['displayName' => ['text' => 'New Business']]);
        $this->assertNull($place['rating']);
        $this->assertSame(0, $place['count']);
        $this->assertSame([], $place['reviews']);
    }

    public function testTheReviewsShownFollowTheLowestRatingAndHowMany(): void
    {
        $reviews = GoogleReviews::normalise($this->place())['reviews'];
        $this->assertSame(['Reviewer One', 'Reviewer Two', 'No text'], array_column(GoogleReviews::select($reviews, 5), 'author'));
        $this->assertSame(['Reviewer One', 'No text'], array_column(GoogleReviews::select($reviews, 5, 4), 'author'), "Google's order kept");
        $this->assertSame(['Reviewer One'], array_column(GoogleReviews::select($reviews, 1), 'author'));
        $this->assertSame([], GoogleReviews::select($reviews, 0));
    }

    public function testTheSiteLanguageAsGooglesLanguageCode(): void
    {
        $this->assertSame('en', GoogleReviews::language('en-GB'));
        $this->assertSame('bn', GoogleReviews::language('bn_IN'));
        $this->assertSame('de', GoogleReviews::language('de'));
    }

    public function testWithoutAKeyOrPlaceNothingIsFetched(): void
    {
        $this->assertFalse((new GoogleReviews('', ''))->isConfigured());
        $this->assertFalse((new GoogleReviews('key', ''))->isConfigured());
        $this->assertTrue((new GoogleReviews('key', 'ChIJx'))->isConfigured());
        $this->assertNull((new GoogleReviews('', ''))->place('en'));
    }
}
