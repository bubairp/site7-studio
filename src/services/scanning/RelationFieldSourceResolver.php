<?php

namespace site7\studio\services\scanning;

/**
 * Resolves a Craft relation field's `sources` setting (Assets/Categories/
 * Tags/Entries - anything extending craft\fields\BaseRelationField) into the
 * uids of the resources it can actually select from, using the same
 * 'volume:{uid}'/'group:{uid}'/'taggroup:{uid}'/'section:{uid}' source-string
 * convention Craft core itself uses. Extracted out of WebsiteImportService
 * (Website Starter Kit System Phase 2) so both it and CraftResourceRegistry's
 * graph builder (Phase 3) share one implementation instead of each
 * re-deriving the same prefix parsing.
 *
 * Static and Craft-app-independent by design - the only Craft awareness this
 * class has is the string convention itself, not a live API call - so it's
 * directly unit-testable (see RelationFieldSourceResolverTest).
 */
class RelationFieldSourceResolver
{
    /**
     * A field configured to allow "all sources" (`sources === '*'`) resolves
     * to every uid of that kind project-wide via $allWhenWildcard, matching
     * how Craft treats that field at query time (no restriction).
     *
     * @param string|array|null $sources
     * @param callable(): array $allWhenWildcard returns every live resource of this kind (each with a ->uid property), for the '*' case
     * @return string[] resolved uids, deduplicated
     */
    public static function resolveUids(string|array|null $sources, string $prefix, callable $allWhenWildcard): array
    {
        $uids = [];
        self::collectUids($sources, $prefix, $uids, $allWhenWildcard);
        return array_keys($uids);
    }

    /**
     * Same resolution, merging into an existing `[uid => true]` map by
     * reference - the shape WebsiteImportService accumulates references in
     * across many fields/entries before resolving them all at once.
     *
     * @param string|array|null $sources
     * @param array<string, true> $uids uid => true, merged into by reference
     * @param callable(): array $allWhenWildcard
     */
    public static function collectUids(string|array|null $sources, string $prefix, array &$uids, callable $allWhenWildcard): void
    {
        if ($sources === '*') {
            foreach ($allWhenWildcard() as $resource) {
                if (isset($resource->uid)) {
                    $uids[$resource->uid] = true;
                }
            }
            return;
        }

        foreach ((array)$sources as $source) {
            if (is_string($source) && str_starts_with($source, $prefix)) {
                $uids[substr($source, strlen($prefix))] = true;
            }
        }
    }
}
