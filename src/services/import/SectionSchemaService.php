<?php

namespace site7\studio\services\import;

use Craft;
use craft\base\Component;
use craft\fields\Matrix;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\models\EntryType;

/**
 * Section package format v2 (docs/14_IMPORT_EXISTING_SECTION.md §v2):
 * a block exactly as Craft defines it, in the package's schema.json.
 *
 * Format v1 (fields.yaml/matrix.yaml) keeps a simplified copy - handles,
 * seven field types, one plain tab - so a real block lost its per-layout
 * field handles (creating duplicate fields), conditions, other field types,
 * nested blocks and UIDs. v2 captures the block's own project config: the
 * entry type, every field its layout uses and, recursively, the entry types
 * of nested Matrix fields and their fields - with their UIDs. Installing
 * applies those configs through Craft's ProjectConfig service (the same
 * handlers Craft runs when applying config/project), so the block comes out
 * identical to the source, and content keyed by layout element UIDs fits.
 *
 * Install only ever creates what's missing (matched by UID). An existing
 * field or entry type is reused, never modified; a different one using the
 * same handle is a conflict and stops the install before anything changes.
 */
class SectionSchemaService extends Component
{
    public const FILE = 'schema.json';
    public const FORMAT_VERSION = 2;

    private const PATH_FIELDS = 'fields';
    private const PATH_ENTRY_TYPES = 'entryTypes';

    /**
     * @return array{formatVersion: int, entryType: string, items: array<int, array{kind: string, uid: string, handle: string, config: array}>}
     *   items in install order (dependencies first)
     */
    public function capture(EntryType $blockType): array
    {
        $items = [];
        $visited = [];
        $this->visit(self::PATH_ENTRY_TYPES, $blockType->uid, $visited, $items);

        return [
            'formatVersion' => self::FORMAT_VERSION,
            'entryType' => $blockType->uid,
            'items' => $items,
        ];
    }

    /**
     * Depth-first, dependencies before dependents. A reference back to an
     * item already being visited (a cycle - blocks nesting each other) is
     * skipped here; install()'s second pass resolves it.
     */
    private function visit(string $kind, string $uid, array &$visited, array &$items): void
    {
        $key = "{$kind}.{$uid}";
        if (isset($visited[$key])) {
            return;
        }
        $visited[$key] = true;

        $config = Craft::$app->getProjectConfig()->get($key);
        if (!is_array($config)) {
            throw new \Exception("{$key} isn't in this site's project config.");
        }

        foreach (self::references($kind, $config) as [$refKind, $refUid]) {
            $this->visit($refKind, $refUid, $visited, $items);
        }

        $items[] = ['kind' => $kind, 'uid' => $uid, 'handle' => (string)($config['handle'] ?? ''), 'config' => $config];
    }

    /**
     * Fields and entry types a config points at: field layout elements'
     * fieldUid (entry type layouts, and fields that carry their own layout),
     * and the entry types a Matrix field allows.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function references(string $kind, array $config): array
    {
        $refs = [];
        array_walk_recursive($config, function($value, $key) use (&$refs) {
            if ($key === 'fieldUid' && is_string($value)) {
                $refs[self::PATH_FIELDS . ".{$value}"] = [self::PATH_FIELDS, $value];
            }
        });

        if ($kind === self::PATH_FIELDS && ($config['type'] ?? null) === Matrix::class) {
            $entryTypes = ProjectConfigHelper::unpackAssociativeArrays(['x' => $config['settings']['entryTypes'] ?? []])['x'];
            foreach ((array)$entryTypes as $entryType) {
                $uid = is_array($entryType) ? ($entryType['uid'] ?? null) : $entryType;
                if (is_string($uid)) {
                    $refs[self::PATH_ENTRY_TYPES . ".{$uid}"] = [self::PATH_ENTRY_TYPES, $uid];
                }
            }
        }

        return array_values($refs);
    }

    /**
     * What installing $schema would do here, without changing anything.
     *
     * @return array{create: array, reuse: array, conflicts: string[], missingTypes: string[]}
     */
    public function plan(array $schema): array
    {
        $plan = ['create' => [], 'reuse' => [], 'conflicts' => [], 'missingTypes' => []];

        foreach ($schema['items'] ?? [] as $item) {
            if ($this->existsByUid($item)) {
                $plan['reuse'][] = $item;
                continue;
            }
            $sameHandle = $item['kind'] === self::PATH_FIELDS
                ? Craft::$app->getFields()->getFieldByHandle($item['handle'])
                : Craft::$app->getEntries()->getEntryTypeByHandle($item['handle']);
            if ($item['handle'] !== '' && $sameHandle) {
                $plan['conflicts'][] = ($item['kind'] === self::PATH_FIELDS ? 'Field' : 'Entry type') . " '{$item['handle']}' already exists here as a different {$item['kind']} (UID {$sameHandle->uid}, the package's is {$item['uid']}).";
                continue;
            }
            $type = $item['config']['type'] ?? null;
            if ($item['kind'] === self::PATH_FIELDS && is_string($type) && !class_exists($type)) {
                $plan['missingTypes'][] = "{$item['handle']} needs field type {$type} - install its plugin first.";
                continue;
            }
            $plan['create'][] = $item;
        }

        return $plan;
    }

    /**
     * Creates the missing parts of the block.
     *
     * @return array{created: string[], reused: string[]} UIDs
     * @throws \Exception on conflicts or missing field types, before changing anything
     */
    public function install(array $schema): array
    {
        if (($schema['formatVersion'] ?? null) !== self::FORMAT_VERSION) {
            throw new \Exception('Unsupported section schema format.');
        }

        $plan = $this->plan($schema);
        if ($plan['conflicts'] || $plan['missingTypes']) {
            throw new \Exception(implode(' ', array_merge($plan['conflicts'], $plan['missingTypes'])));
        }

        $projectConfig = Craft::$app->getProjectConfig();
        // Pass 1, dependencies first. Pass 2 re-applies what was created, so
        // references that were still missing during pass 1 (cycles) resolve.
        foreach ($plan['create'] as $item) {
            $projectConfig->set("{$item['kind']}.{$item['uid']}", $item['config'], "Site7 Studio: install {$item['kind']} {$item['handle']}");
        }
        foreach ($plan['create'] as $item) {
            $projectConfig->set("{$item['kind']}.{$item['uid']}", $item['config'], null, true, true);
        }

        foreach ($plan['create'] as $item) {
            if (!$this->existsByUid($item)) {
                throw new \Exception("Craft did not create {$item['kind']} '{$item['handle']}' ({$item['uid']}).");
            }
        }

        return [
            'created' => array_column($plan['create'], 'uid'),
            'reused' => array_column($plan['reuse'], 'uid'),
        ];
    }

    /**
     * Adds an entry type to a Matrix field's allowed entry types, editing the
     * field's own config so other entries (and their add-menu groups) are
     * kept as they are. No-op when it's already there.
     */
    public function linkToMatrix(Matrix $matrixField, string $entryTypeUid, bool $add = true): void
    {
        $path = self::PATH_FIELDS . ".{$matrixField->uid}";
        $config = Craft::$app->getProjectConfig()->get($path);
        if (!is_array($config)) {
            return;
        }

        $config = ProjectConfigHelper::unpackAssociativeArrays($config);
        $entryTypes = array_values((array)($config['settings']['entryTypes'] ?? []));
        $uids = array_map(fn($et) => is_array($et) ? ($et['uid'] ?? null) : $et, $entryTypes);
        $present = in_array($entryTypeUid, $uids, true);

        if ($add && !$present) {
            $entryTypes[] = ['uid' => $entryTypeUid];
        } elseif (!$add && $present) {
            $entryTypes = array_values(array_filter($entryTypes, fn($et) => (is_array($et) ? ($et['uid'] ?? null) : $et) !== $entryTypeUid));
        } else {
            return;
        }

        $config['settings']['entryTypes'] = $entryTypes;
        Craft::$app->getProjectConfig()->set($path, $config, "Site7 Studio: " . ($add ? 'add' : 'remove') . " block in {$matrixField->handle}");
    }

    private function existsByUid(array $item): bool
    {
        return $item['kind'] === self::PATH_FIELDS
            ? Craft::$app->getFields()->getFieldByUid($item['uid']) !== null
            : Craft::$app->getEntries()->getEntryTypeByUid($item['uid']) !== null;
    }
}
