<?php

namespace site7\studio\models\import;

use craft\base\Model;

/**
 * The result of PageDependencyResolverService::resolve() for a single Entry -
 * Phase 9.2's "Smart Dependency Import"/"Dependency Preview". Informational
 * only: nothing here is written anywhere by this model, it only feeds the
 * Import Existing Page wizard's Preview step.
 */
class PageDependencyPreview extends Model
{
    public string $sectionHandle = '';
    public string $sectionName = '';
    public string $entryTypeHandle = '';
    public string $entryTypeName = '';

    /**
     * @var array<int, array{entryTypeHandle: string, name: string, packageStatus: string, packageHandle: ?string}>
     * packageStatus: 'available' (a Section package already tracks this Entry Type's uid) | 'missing' (Import Existing Section first).
     */
    public array $matrixBlocks = [];

    /** @var array<int, array{id: int, title: string, groupName: string}> Actually-selected Category/Tag terms, read from live field values - never fabricated. */
    public array $categories = [];

    /** @var array<int, array{id: int, filename: string}> Actually-selected Assets, read from live field values. */
    public array $assets = [];

    /**
     * Global Sets this page structurally depends on. Always empty today - a
     * single Entry has no structural link to a Global Set in Craft (Global
     * Sets are project-wide, not referenced by a page's own fields); this
     * key is kept for schema forward-compatibility with the Dependency
     * Preview's documented shape rather than omitted outright.
     *
     * @var array<int, array{handle: string, name: string}>
     */
    public array $globals = [];

    /** @var array<int, array{fieldHandle: string, menuName: string}> Real navigation menus referenced by this page's own fields (NavigationScanner), same signal WebsiteImportService captures per-page. */
    public array $navigation = [];

    public int $pageCount = 1;

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['sectionHandle', 'sectionName', 'entryTypeHandle', 'entryTypeName'], 'string'];
        $rules[] = [['pageCount'], 'integer'];
        $rules[] = [['matrixBlocks', 'categories', 'assets', 'globals', 'navigation'], 'safe'];
        return $rules;
    }

    /** Total dependency count for the "1 Page, N Dependencies" summary line. */
    public function dependencyCount(): int
    {
        return count($this->matrixBlocks) + count($this->categories) + count($this->assets) + count($this->globals) + count($this->navigation);
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        $data = parent::toArray($fields, $expand, $recursive);
        $data['dependencyCount'] = $this->dependencyCount();
        return $data;
    }
}
