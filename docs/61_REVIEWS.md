# 61 — Reviews block: platform reviews

## 1. Purpose

The Reviews block (rp-craft `templates/_blocks/reviews.twig`, an extra block, entry type `reviews`) shows a business's **real** reviews, read from the platforms with **the site's own keys**. Nothing is typed into the CMS: the block holds display settings only. Quotes sent to a business directly belong in the Testimonials block. Decided with the user on 2026-10-29: a first version with a Reviews channel (entries typed in the CMS) was dropped; no shared Site7 key (each site pays and controls its own platform accounts).

## 2. Platforms

| Platform | Source class | API | Gives |
|---|---|---|---|
| Google | `GoogleReviews` | Places API (New), `GET places/{placeId}` | rating, count, Maps URL, **at most 5 reviews** (Google's choice and order): author, profile URL, photo, stars, original text, time, relative time, review URL |
| Trustpilot | `TrustpilotReviews` | public API v1: `business-units/{id}` and `…/reviews` (20 newest) | TrustScore, count, profile URL; reviews with stars, title, text, time, review URL, consumer profile URL |
| Facebook | `FacebookReviews` | Graph API v21.0: `{page}` and `{page}/ratings` (25) | overall star rating (when the Page has one), count, `…/reviews` URL; recommendations (`recommends` true/false, stars only on older ones), text, time; Meta often leaves the reviewer out |

The block's `reviewPlatform`: `all` (default: every connected platform, mixed newest first), `google`, `trustpilot`, `facebook`. A platform that isn't connected is skipped.

## 3. Connection (per site, `.env`)

| Platform | Variables |
|---|---|
| Google | `GOOGLE_PLACES_API_KEY` (Google Cloud project, Places API (New) enabled, billing on; restrict the key to that API and the server's IP), `GOOGLE_PLACE_ID` (`php craft site7-studio/reviews/test "<business name, city>"` finds it) |
| Trustpilot | `TRUSTPILOT_API_KEY`, `TRUSTPILOT_BUSINESS_UNIT_ID` (the business's Trustpilot account; API access depends on its plan) |
| Facebook | `FACEBOOK_PAGE_ID`, `FACEBOOK_PAGE_TOKEN` (a long-lived Page access token with `pages_read_user_content`) |

The matching `Settings` properties (`googlePlacesApiKey`, `googlePlaceId`, `trustpilotApiKey`, `trustpilotBusinessUnitId`, `facebookPageId`, `facebookPageToken`) win when a site's `config/site7-studio.php` sets them (`$ENV` syntax works); otherwise each source reads its variables from the environment itself, since a customer's config file may not map them. Keys never go into project config.

## 4. Fetching and caching (`services/reviews/ReviewSource`)

- Each platform's place is fetched and normalised to one shape (`ReviewSource` docblock: place `{platform, name, rating, count, url, reviews[]}`, review `{platform, author, authorUrl, photo, rating, recommends, title, text, time, relativeTime, url}`), cached `CACHE_TTL` (12 h, key: platform + account + language), with a copy for `STALE_TTL` (7 days) returned while the platform fails (logged as a warning). Unconfigured: null, no request.
- `select()`: at least the lowest rating (a recommendation passes when it recommends), at most the limit. `merge()`: several platforms' reviews, newest first.
- Reviews are never stored as content, which also keeps within the platforms' terms on storing their content.

## 5. Template side

`craft.site7Reviews` (`variables/ReviewsVariable`, on `CraftVariable::EVENT_INIT`): `get(platform, limit, minimum)` → `{platform, places[] (no reviews), reviews[]}` or null; `configured(platform)`; `envNames(platform)`; `google()` and `googleConfigured()` (1.2.x's API, kept). The block checks `craft.has('site7Reviews')` first: on an older Site7 Studio, `??` and `is defined` make Craft's variable call the missing service and throw. The block package names `minimumSite7Version: 1.3.0` (`get()`), so Commerce24 never offers it to an older plugin.

Shown as the platforms ask: each platform's mark on its rating badge (linking to its page) and on each card; the reviewer's name, photo and profile link where given; the text as written (clipped to six lines, "Read on <platform>"); a Trustpilot title; a Facebook recommendation as "Recommends" / "Doesn't recommend". Not connected, unreachable or nothing at the chosen rating: hidden from visitors, an admin-only note names the `.env` values missing.

## 6. Block fields

`headingAndContent`, `reviewPlatform` (new), `showSummary` (`switchOff`), `reviewLimit` (empty: 6; 0: badges only) and `minimumRating` (`number`), `displayView` (slider default, grid, masonry, list), `itemsPerRow`, `button`, `blockStyle`. Structure script: rp-craft `.claude/skills/site7-block-authoring/examples/reviews-structure.php`.

## 7. Console

`php craft site7-studio/reviews/test [--platform=all|google|trustpilot|facebook]`: per platform, connected or which variables are missing, and what it returns now (cache bypassed). With a name (`reviews/test "<name, city>"`): Google places matching it and their Place IDs.

## 8. Tests

`tests/unit/services/reviews/GoogleReviewsTest`, `ReviewSourcesTest`: each platform's response as a fixture in the shared shape, selection with recommendations, newest-first merge, connection values. Live APIs: `reviews/test` on a site with keys.
