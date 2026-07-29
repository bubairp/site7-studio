<?php

namespace site7\studio\models\project;

/**
 * DependencyAnalyzer's output (Website Starter Kit System Phase 5): a
 * deterministic, ordered installation plan scoped to exactly the resources
 * one Project actually needs - not a dump of the whole Craft project's
 * resource graph. Every element is a plain, JSON-serializable array (never
 * a live Craft object or a ResourceNode), since this feeds BlueprintBuilder,
 * which must stay independent of the final package format.
 *
 * Item shape throughout: {type, key, handle, label} - `type` is one of
 * CraftResourceRegistry::KIND_* for native Craft resources, or 'page'/
 * 'npm-install'/'build' for the non-Craft-resource items (captured content,
 * frontend steps) DependencyAnalyzer adds after the graph-derived ones.
 */
final class InstallationPlan
{
    public function __construct(
        /** @var array<int, array{name: string, items: array}> ordered plugins -> schema -> content -> frontend */
        public readonly array $waves,
        /** @var array<int, array{type: string, key: string, handle: string, label: string}> resources that could not be fully ordered (participate in a cycle) */
        public readonly array $cyclicResources,
        /** @var array<int, array{from: array, to: array}> "depends on" edges, both ends scoped to this plan's resources */
        public readonly array $dependencyRelationships,
    ) {
    }

    public function toArray(): array
    {
        return [
            'waves' => $this->waves,
            'cyclicResources' => $this->cyclicResources,
            'dependencyRelationships' => $this->dependencyRelationships,
        ];
    }
}
