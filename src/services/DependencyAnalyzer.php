<?php

namespace site7\studio\services;

use craft\base\Component;
use site7\studio\models\packages\PackageManifest;
use site7\studio\models\project\InstallationPlan;
use site7\studio\models\project\Project;
use site7\studio\models\registry\ResourceNode;

/**
 * Turns a Project into a deterministic, ordered InstallationPlan (Website
 * Starter Kit System Phase 5) - merging native Craft resource dependencies
 * (via CraftResourceRegistry's graph) with the plugin/npm/frontend
 * dependencies Phase 4 captured, and reporting circular dependencies
 * explicitly rather than failing or silently degrading.
 *
 * CraftResourceRegistry's graph covers the *entire* Craft project (every
 * Section/Entry Type/Field/etc, whether or not this particular package
 * references it) - this analyzer's first job is narrowing that down to the
 * closure of resources this specific Project actually needs (every resource
 * the manifest itself lists, plus their transitive dependencies), so the
 * resulting plan describes installing *this package*, not the whole site.
 */
class DependencyAnalyzer extends Component
{
    private const SCHEMA_KINDS = [
        CraftResourceRegistry::KIND_SECTION,
        CraftResourceRegistry::KIND_ENTRY_TYPE,
        CraftResourceRegistry::KIND_FIELD,
        CraftResourceRegistry::KIND_ASSET_VOLUME,
        CraftResourceRegistry::KIND_CATEGORY_GROUP,
        CraftResourceRegistry::KIND_TAG_GROUP,
        CraftResourceRegistry::KIND_GLOBAL_SET,
        CraftResourceRegistry::KIND_NAVIGATION,
    ];

    public function analyze(Project $project): InstallationPlan
    {
        $registry = $project->registry;
        $manifest = $project->manifest;

        $closureNodes = $this->computeClosure($registry, $this->collectSeedNodes($registry, $manifest));
        $closureKeys = array_flip(array_map(self::nodeKey(...), $closureNodes));

        $cycles = $registry->analyzeCycles();
        $orderedInClosure = array_values(array_filter($cycles['ordered'], fn(ResourceNode $n) => isset($closureKeys[self::nodeKey($n)])));
        $cyclicInClosure = array_values(array_filter($cycles['cyclic'], fn(ResourceNode $n) => isset($closureKeys[self::nodeKey($n)])));

        $pluginItems = $this->describeNodes(array_values(array_filter($orderedInClosure, fn(ResourceNode $n) => $n->kind === CraftResourceRegistry::KIND_PLUGIN)));
        $schemaItems = $this->describeNodes(array_values(array_filter($orderedInClosure, fn(ResourceNode $n) => in_array($n->kind, self::SCHEMA_KINDS, true))));

        $dependencyRelationships = [];
        foreach ($registry->allEdges() as $edge) {
            if (isset($closureKeys[self::nodeKey($edge['from'])]) && isset($closureKeys[self::nodeKey($edge['to'])])) {
                $dependencyRelationships[] = ['from' => $this->describeNode($edge['from']), 'to' => $this->describeNode($edge['to'])];
            }
        }

        return new InstallationPlan(
            waves: [
                ['name' => 'plugins', 'items' => $pluginItems],
                ['name' => 'schema', 'items' => $schemaItems],
                ['name' => 'content', 'items' => self::buildContentItems($manifest)],
                ['name' => 'frontend', 'items' => self::buildFrontendItems($manifest)],
            ],
            cyclicResources: $this->describeNodes($cyclicInClosure),
            dependencyRelationships: $dependencyRelationships,
        );
    }

    /**
     * The starting points for the closure walk: every native Craft resource
     * this Project's manifest actually references by handle. A handle the
     * Registry doesn't recognize (resolved but since-deleted) is skipped,
     * not an error - the manifest's own captured settings are still valid
     * even if the live resource is now gone.
     *
     * @return ResourceNode[]
     */
    private function collectSeedNodes(CraftResourceRegistry $registry, PackageManifest $manifest): array
    {
        $seeds = [];
        $add = function (?ResourceNode $node) use (&$seeds) {
            if ($node) {
                $seeds[] = $node;
            }
        };

        foreach ($manifest->pages as $page) {
            if (!empty($page['entryTypeHandle'])) {
                $add($registry->findByHandle(CraftResourceRegistry::KIND_ENTRY_TYPE, $page['entryTypeHandle']));
            }
        }
        foreach ($manifest->craftSections as $section) {
            $add($registry->findByHandle(CraftResourceRegistry::KIND_SECTION, $section['handle']));
        }
        foreach ($manifest->assetVolumes as $volume) {
            $add($registry->findByHandle(CraftResourceRegistry::KIND_ASSET_VOLUME, $volume['handle']));
        }
        foreach ($manifest->categoryGroups as $group) {
            $add($registry->findByHandle(CraftResourceRegistry::KIND_CATEGORY_GROUP, $group['handle']));
        }
        foreach ($manifest->tagGroups as $group) {
            $add($registry->findByHandle(CraftResourceRegistry::KIND_TAG_GROUP, $group['handle']));
        }
        foreach ($manifest->globals as $global) {
            $add($registry->findByHandle(CraftResourceRegistry::KIND_GLOBAL_SET, $global['globalSetHandle']));
        }
        foreach (($manifest->dependencies['plugins'] ?? []) as $plugin) {
            $add($registry->findByHandle(CraftResourceRegistry::KIND_PLUGIN, $plugin['handle']));
        }

        return $seeds;
    }

    /**
     * Breadth-first walk of every dependency reachable from $seeds -
     * "everything this Project needs to exist before it can be installed."
     *
     * @param ResourceNode[] $seeds
     * @return ResourceNode[]
     */
    private function computeClosure(CraftResourceRegistry $registry, array $seeds): array
    {
        $visited = [];
        $result = [];
        $queue = $seeds;

        while (!empty($queue)) {
            $node = array_shift($queue);
            $key = self::nodeKey($node);
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;
            $result[] = $node;

            foreach ($registry->dependenciesOf($node) as $dependency) {
                if (!isset($visited[self::nodeKey($dependency)])) {
                    $queue[] = $dependency;
                }
            }
        }

        return $result;
    }

    /**
     * Static and Craft-app-independent by design (pure array shaping off an
     * already-built PackageManifest) - the 'content'/'frontend' waves don't
     * need graph traversal at all, so these are directly unit-testable
     * without a live Registry (see DependencyAnalyzerTest).
     *
     * @return array<int, array{type: string, key: string, handle: string, label: string}>
     */
    public static function buildContentItems(PackageManifest $manifest): array
    {
        $items = [];
        foreach ($manifest->pages as $page) {
            $items[] = ['type' => 'page', 'key' => $page['slug'] ?? $page['title'] ?? '', 'handle' => $page['templateHandle'] ?? '', 'label' => $page['title'] ?? ''];
        }
        foreach ($manifest->globals as $global) {
            $items[] = ['type' => CraftResourceRegistry::KIND_GLOBAL_SET, 'key' => $global['globalSetHandle'], 'handle' => $global['globalSetHandle'], 'label' => $global['name']];
        }
        return $items;
    }

    /** @return array<int, array{type: string, key: string, handle: string, label: string}> */
    public static function buildFrontendItems(PackageManifest $manifest): array
    {
        $items = [];
        $npmPackages = $manifest->dependencies['npmPackages'] ?? [];
        if (!empty($npmPackages)) {
            $items[] = ['type' => 'npm-install', 'key' => 'npm-install', 'handle' => 'npm-install', 'label' => 'Install ' . count($npmPackages) . ' npm package(s)'];
        }
        $frontendTooling = $manifest->dependencies['frontendTooling'] ?? [];
        if (!empty($frontendTooling['configFiles'])) {
            $system = $frontendTooling['system'] ?? 'plain';
            $items[] = ['type' => 'build', 'key' => 'build', 'handle' => 'build', 'label' => "Run {$system} build"];
        }
        return $items;
    }

    /** @return array<int, array{type: string, key: string, handle: string, label: string}> */
    private function describeNodes(array $nodes): array
    {
        return array_map($this->describeNode(...), $nodes);
    }

    private function describeNode(ResourceNode $node): array
    {
        return ['type' => $node->kind, 'key' => $node->key, 'handle' => $node->handle, 'label' => $node->name];
    }

    private static function nodeKey(ResourceNode $node): string
    {
        return $node->kind . ':' . $node->key;
    }
}
