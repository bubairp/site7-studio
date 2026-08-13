<?php

namespace site7\studio\services\import;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\models\Section;
use site7\studio\repositories\PageImportSourceRepository;
use site7\studio\services\CraftResourceRegistry;
use site7\studio\Site7Studio;

/**
 * Phase 9.2's reusable **Website Tree** data builder - mirrors Craft's own
 * native hierarchy (Singles/Channels/Structures/Categories) rather than the
 * old flat, ungrouped "Import Existing Page" list. Read-only, no writes.
 *
 * Every node additionally carries {importStatus, existingPackageHandle,
 * existingPackageId} (via PageImportSourceRepository, keyed by the Entry's
 * own uid) - additive keys any future non-import consumer of this same tree
 * (Import Existing Website, Starter Kit Details, Synchronization/
 * Installation Preview, per this phase's "reusable component" requirement)
 * can simply ignore.
 *
 * Structure/Category nesting is built in a single O(n) pass per section/
 * group using Craft's own `lft`/`level` structure columns (one query, then
 * a stack-based nest) rather than per-node getParent()/getChildren() calls,
 * so this stays fast on large sites.
 */
class WebsiteTreeService
{
    /**
     * @return array{singles: array, channels: array, structures: array, categories: array, globalSets: array}
     */
    public function buildTree(): array
    {
        $sourceRepo = new PageImportSourceRepository();
        $matrixHandle = $this->getMatrixFieldHandle();

        $singles = [];
        $channels = [];
        $structures = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            /** @var Section $section */
            if ($section->type === 'single') {
                foreach ($this->getSectionEntries($section) as $entry) {
                    $singles[] = $this->describeEntry($entry, $sourceRepo, $matrixHandle);
                }
            } elseif ($section->type === 'channel') {
                $channels[] = [
                    'handle' => $section->handle,
                    'name' => $section->name,
                    'entries' => array_map(
                        fn(Entry $e) => $this->describeEntry($e, $sourceRepo, $matrixHandle),
                        $this->getSectionEntries($section)
                    ),
                ];
            } else {
                $structures[] = [
                    'handle' => $section->handle,
                    'name' => $section->name,
                    'entries' => $this->nestEntries($this->getSectionEntries($section), $sourceRepo, $matrixHandle),
                ];
            }
        }

        $categories = [];
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $terms = Category::find()->groupId($group->id)->status(null)->orderBy('lft')->all();
            $categories[] = [
                'handle' => $group->handle,
                'name' => $group->name,
                'terms' => $this->nestCategories($terms),
            ];
        }

        // Phase 9.3: Global Sets aren't part of Craft's page hierarchy, but
        // "Import Existing Website" needs them alongside the tree (a
        // Starter Kit captures selected Global Set values too) - same
        // mapping actionGetWebsiteResources() already used, added here so
        // that endpoint can be fully replaced by this one.
        $globalSets = array_map(fn($node) => [
            'id' => $node->resource->id,
            'handle' => $node->handle,
            'name' => $node->name,
            'likelyNav' => (bool)preg_match('/nav/i', $node->handle . ' ' . $node->name),
        ], (new CraftResourceRegistry())->all(CraftResourceRegistry::KIND_GLOBAL_SET));

        return ['singles' => $singles, 'channels' => $channels, 'structures' => $structures, 'categories' => $categories, 'globalSets' => array_values($globalSets)];
    }

    /**
     * @return Entry[]
     */
    private function getSectionEntries(Section $section): array
    {
        // Only Structure sections have lft/rgt/level - Single/Channel
        // entries aren't part of a structure at all, so ordering by `lft`
        // for those throws "Unknown column".
        $query = Entry::find()->sectionId($section->id)->status(null);
        return $section->type === 'structure'
            ? $query->orderBy(['lft' => SORT_ASC])->all()
            : $query->orderBy('title')->all();
    }

    /**
     * Stack-based O(n) nest by `level` - the same technique Craft's own CP
     * structure views use, just without a second query per node.
     *
     * @param Entry[] $entries already ordered by `lft`
     * @return array<int, array>
     */
    private function nestEntries(array $entries, PageImportSourceRepository $sourceRepo, ?string $matrixHandle): array
    {
        $roots = [];
        /** @var array<int, array> $parents level => &that level's most recent node's children array */
        $parents = [];

        foreach ($entries as $entry) {
            $node = $this->describeEntry($entry, $sourceRepo, $matrixHandle);
            $node['children'] = [];
            $level = (int)$entry->level;

            if ($level <= 1 || !isset($parents[$level - 1])) {
                $roots[] = $node;
                $parents[$level] = &$roots[count($roots) - 1]['children'];
            } else {
                $parents[$level - 1][] = $node;
                $lastIndex = count($parents[$level - 1]) - 1;
                $parents[$level] = &$parents[$level - 1][$lastIndex]['children'];
            }
        }

        return $roots;
    }

    /**
     * @param Category[] $terms already ordered by `lft`
     * @return array<int, array>
     */
    private function nestCategories(array $terms): array
    {
        $roots = [];
        /** @var array<int, array> $parents level => &that level's most recent node's children array */
        $parents = [];

        foreach ($terms as $term) {
            $node = ['id' => $term->id, 'uid' => $term->uid, 'title' => $term->title, 'slug' => $term->slug, 'children' => []];
            $level = (int)$term->level;

            if ($level <= 1 || !isset($parents[$level - 1])) {
                $roots[] = $node;
                $parents[$level] = &$roots[count($roots) - 1]['children'];
            } else {
                $parents[$level - 1][] = $node;
                $lastIndex = count($parents[$level - 1]) - 1;
                $parents[$level] = &$parents[$level - 1][$lastIndex]['children'];
            }
        }

        return $roots;
    }

    /**
     * @return array{id: int, uid: string, title: string, slug: ?string, hasSite7Content: bool, importStatus: string, existingPackageHandle: ?string, existingPackageId: ?int}
     */
    private function describeEntry(Entry $entry, PageImportSourceRepository $sourceRepo, ?string $matrixHandle): array
    {
        $hasSite7Content = false;
        if ($matrixHandle && $entry->getFieldLayout()?->getFieldByHandle($matrixHandle)) {
            $fieldValue = $entry->getFieldValue($matrixHandle);
            $hasSite7Content = $fieldValue && $fieldValue->status(null)->drafts(null)->savedDraftsOnly(false)->count() > 0;
        }

        $sourceRecord = $sourceRepo->findBySourceUid($entry->uid);
        $existingPackageHandle = null;
        $existingPackageId = null;
        $importStatus = 'not-imported';

        if ($sourceRecord) {
            $package = \site7\studio\records\PackageRecord::findOne($sourceRecord->packageId);
            $existingPackageHandle = $package?->handle;
            $existingPackageId = $sourceRecord->packageId;
            $currentHash = (new EntrySourceHasher())->computeHash($entry);
            $importStatus = $currentHash === $sourceRecord->sourceHash ? 'imported' : 'update-available';
        }

        return [
            'id' => $entry->id,
            'uid' => $entry->uid,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'hasSite7Content' => $hasSite7Content,
            'importStatus' => $importStatus,
            'existingPackageHandle' => $existingPackageHandle,
            'existingPackageId' => $existingPackageId,
        ];
    }

    private function getMatrixFieldHandle(): ?string
    {
        $settings = Site7Studio::getInstance()->getSettings();
        if (!$settings->matrixFieldId) {
            return null;
        }
        $field = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
        return $field?->handle;
    }

    /**
     * Phase 9.3: annotates a tree (as returned by buildTree()) with an
     * `included` boolean per entry node - whether its uid is part of a given
     * Starter Kit's tracked page selection. Additive/optional - only the
     * Starter Kit Details display (Package Editor's read-only tree for an
     * imported Starter Kit) uses this; Import Existing Page/Website ignore
     * it. Categories are left untouched (never part of a page selection).
     *
     * @param string[] $includedUids
     */
    public function markIncluded(array $tree, array $includedUids): array
    {
        $includedSet = array_flip($includedUids);

        $markEntries = function (array $entries) use (&$markEntries, $includedSet): array {
            return array_map(function (array $entry) use ($markEntries, $includedSet) {
                $entry['included'] = isset($includedSet[$entry['uid']]);
                if (!empty($entry['children'])) {
                    $entry['children'] = $markEntries($entry['children']);
                }
                return $entry;
            }, $entries);
        };

        $tree['singles'] = $markEntries($tree['singles'] ?? []);
        $tree['channels'] = array_map(function (array $section) use ($markEntries) {
            $section['entries'] = $markEntries($section['entries'] ?? []);
            return $section;
        }, $tree['channels'] ?? []);
        $tree['structures'] = array_map(function (array $section) use ($markEntries) {
            $section['entries'] = $markEntries($section['entries'] ?? []);
            return $section;
        }, $tree['structures'] ?? []);

        return $tree;
    }
}
