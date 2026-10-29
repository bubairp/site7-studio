<?php

namespace site7\studio\services\reviews;

use site7\studio\Site7Studio;

/**
 * A Facebook Page's rating and recommendations for the Reviews block (docs/61), read from
 * Meta's Graph API with the site's own Page ID and a long-lived Page access token (reading a
 * Page's ratings needs the pages_read_user_content permission). Facebook reviews are
 * recommendations: recommends true/false, with stars only on older ones; Meta often leaves
 * the reviewer out. Caching: ReviewSource.
 */
class FacebookReviews extends ReviewSource
{
    private const ENDPOINT = 'https://graph.facebook.com/v21.0/';
    private const LIMIT = 25;

    private ?string $pageId;
    private ?string $token;

    /** Given, else the plugin settings, else FACEBOOK_PAGE_ID and FACEBOOK_PAGE_TOKEN. */
    public function __construct(?string $pageId = null, ?string $token = null)
    {
        $settings = Site7Studio::getInstance()?->getSettings();
        $this->pageId = self::value($pageId, $settings?->facebookPageId, 'FACEBOOK_PAGE_ID');
        $this->token = self::value($token, $settings?->facebookPageToken, 'FACEBOOK_PAGE_TOKEN');
    }

    public function platform(): string
    {
        return 'facebook';
    }

    public static function envNames(): array
    {
        return ['FACEBOOK_PAGE_ID', 'FACEBOOK_PAGE_TOKEN'];
    }

    public function isConfigured(): bool
    {
        return $this->pageId !== null && $this->token !== null;
    }

    protected function cacheId(string $language): string
    {
        return (string)$this->pageId;
    }

    protected function fetch(string $language): array
    {
        $page = rawurlencode((string)$this->pageId);
        $info = $this->getJson(self::ENDPOINT, $page, ['query' => [
            'fields' => 'name,link,overall_star_rating,rating_count', 'access_token' => $this->token,
        ]]);
        $ratings = $this->getJson(self::ENDPOINT, "{$page}/ratings", ['query' => [
            'fields' => 'created_time,recommendation_type,review_text,rating,reviewer{name,picture{url}}',
            'limit' => self::LIMIT, 'access_token' => $this->token,
        ]]);

        return self::normalise($info, $ratings);
    }

    /** A Page and its ratings (Graph API) in the shared shape (ReviewSource). */
    public static function normalise(array $info, array $ratings): array
    {
        $link = $info['link'] ?? null;
        $reviewsUrl = $link ? rtrim($link, '/') . '/reviews' : null;
        $list = [];
        foreach ($ratings['data'] ?? [] as $rating) {
            $type = $rating['recommendation_type'] ?? null;
            $list[] = [
                'platform' => 'facebook',
                'author' => (string)($rating['reviewer']['name'] ?? ''),
                'authorUrl' => null,
                'photo' => $rating['reviewer']['picture']['data']['url'] ?? null,
                'rating' => isset($rating['rating']) ? (float)$rating['rating'] : null,
                'recommends' => $type === null ? null : $type === 'positive',
                'title' => null,
                'text' => (string)($rating['review_text'] ?? ''),
                'time' => isset($rating['created_time']) ? date(DATE_ATOM, strtotime($rating['created_time'])) : null,
                'relativeTime' => null,
                'url' => $reviewsUrl,
            ];
        }

        return [
            'platform' => 'facebook',
            'name' => (string)($info['name'] ?? ''),
            'rating' => isset($info['overall_star_rating']) ? (float)$info['overall_star_rating'] : null,
            'count' => (int)($info['rating_count'] ?? 0),
            'url' => $reviewsUrl,
            'reviews' => $list,
        ];
    }
}
