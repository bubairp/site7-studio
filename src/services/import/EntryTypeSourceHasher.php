<?php

namespace site7\studio\services\import;

use craft\models\EntryType;
use site7\studio\Site7Studio;

/**
 * Computes a deterministic hash of a live Craft Entry Type's structure - the
 * Phase 9.1 `sourceHash` used to detect when an imported Section package's
 * source has changed since import ("Update Available"). Reuses
 * CraftResourceService::describeFieldLayout() (the same read every Resource
 * Importer/discovery service already uses) rather than a second field-
 * reading routine - this class only normalizes and hashes that output.
 *
 * Deliberately narrower than the field data captured into fields.yaml: only
 * handle/type/instructions/settings feed the hash (name is excluded so a
 * cosmetic Entry Type rename alone doesn't trigger "Update Available" - the
 * package's own name is independent SITE7 metadata once imported). Same
 * "hash of sorted, normalized structure" approach as
 * PackageArchiveHelper::computeDirectoryChecksum(), just over live Craft
 * data instead of files on disk.
 */
class EntryTypeSourceHasher
{
    public function computeHash(EntryType $entryType): string
    {
        $layout = $entryType->getFieldLayout();
        $craftResourceService = Site7Studio::getInstance()->craftResourceGenerator;
        $describedFields = $layout ? $craftResourceService->describeFieldLayout($layout) : [];

        $normalized = array_map(fn(array $f) => [
            'handle' => $f['handle'],
            'type' => $f['type'],
            'instructions' => $f['instructions'],
            'settings' => $f['settings'] ?? [],
        ], $describedFields);

        usort($normalized, fn($a, $b) => $a['handle'] <=> $b['handle']);

        return hash('sha256', json_encode([
            'handle' => $entryType->handle,
            'fields' => $normalized,
        ], JSON_UNESCAPED_SLASHES));
    }
}
