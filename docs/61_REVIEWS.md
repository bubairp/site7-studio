# 61 — Reviews block: platform reviews

## 1. Purpose

The Reviews block (rp-craft `templates/_blocks/reviews.twig`, an extra block, entry type `reviews`) shows a business's **real** reviews, read from the platform. Nothing is typed into the CMS: the block holds display settings only, and Site7 Studio fetches the rating and reviews. Quotes sent to a business directly belong in the Testimonials block. Decided with the user on 2026-10-29 after a first version with a Reviews channel (entries typed in the CMS) was dropped.

## 2. Platforms

| Platform | How | Gives | Status |
|---|---|---|---|
| Google | Places API (New), `GET places/{placeId}` with the site's key | overall rating, total count, Maps URL, **at most 5 reviews** (Google's choice and order), each with author name, profile URL, photo, rating, original text, publish time, relative time, review URL | built |
| Trustpilot | official TrustBox, or the Trustpilot API for accounts with access | score, count, reviews | next |
| Facebook | Graph API page ratings (Page token, Meta app review) | recommendations | later |

The block's `reviewPlatform` dropdown only offers platforms that are built, so a block can't point at one that shows nothing.

## 3. Connection (per site)

| What | Where |
|---|---|
| Google Places API key | `GOOGLE_PLACES_API_KEY` in `.env` (Google Cloud project, Places API (New) enabled, billing on; restrict the key to Places API) |
| Google Place ID (`ChIJ…`) | `GOOGLE_PLACE_ID` in `.env`. Find it: `php craft site7-studio/reviews/test "<business name, city>"` |

`Settings::$googlePlacesApiKey` / `$googlePlaceId` take precedence when a site's `config/site7-studio.php` sets them (`$ENV` syntax works); otherwise `GoogleReviews` reads the two variables from the environment itself, since a customer site's config file may not map them. The key never goes into project config.

## 4. Fetching and caching (`services/reviews/GoogleReviews`)

- `place()`: the place, normalised (`normalise()`), from Craft's cache when there (`CACHE_TTL` 12 h, keyed by Place ID and language). A fetch also stores a copy for `STALE_TTL` (7 days), returned when Google fails (logged as a warning), so a short outage doesn't empty the block. Unconfigured: null, no request.
- Field mask `id,displayName,rating,userRatingCount,googleMapsUri,reviews`; language from the current site (`language()`: `en-GB` → `en`).
- `normalise()`: the template's shape (`name`, `rating`, `count`, `url`, `reviews[]` with `author`, `authorUrl`, `photo`, `rating`, `text`, `time`, `relativeTime`, `url`). The **original** text is used, not Google's translation.
- `select()`: at least `minimumRating` stars, at most `limit`, Google's order kept.
- `search()`: Text Search for the Place ID (console only).
- Reviews are never stored as content, which also keeps within Google's terms on storing Places content.

## 5. Template side

`craft.site7Reviews` (`variables/ReviewsVariable`, registered on `CraftVariable::EVENT_INIT`): `google(limit, minimum)` and `googleConfigured()`. The block checks `craft.has('site7Reviews')` first: on a site whose Site7 Studio predates it, `??` and `is defined` make Craft's variable call the missing service and throw.

Shown as Google asks: its "G" with the rating badge (linking to the listing) and on each card; each reviewer's name and photo linking to their Google profile; the text as written (clipped to six lines, with "Read on Google"). Unconnected, unreachable or nothing at the chosen rating: hidden from visitors, an admin-only note says which.

## 6. Block fields

`headingAndContent`, `reviewPlatform` (new), `showSummary` (`switchOff`), `reviewLimit` and `minimumRating` (`number`), `displayView` (slider default, grid, masonry, list), `itemsPerRow`, `button`, `blockStyle`. Structure script: rp-craft `.claude/skills/site7-block-authoring/examples/reviews-structure.php`.

## 7. Console

`php craft site7-studio/reviews/test ["<name, city>"]`: with a name, the matching places and their Place IDs; without, what Google returns now for `GOOGLE_PLACE_ID` (cache bypassed).

## 8. Tests

`tests/unit/services/reviews/GoogleReviewsTest` (a Places API response as a fixture: normalising, original text, missing fields, selection, language, no request when unconfigured). The live API is checked with `reviews/test` on a site with a key.
