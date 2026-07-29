<?php

namespace site7\studio\models\registry;

/**
 * An in-memory graph of every ResourceNode CraftResourceRegistry discovered
 * for the current session, plus the "depends on" edges between them (e.g. a
 * Section depends on its Entry Types; an Assets field depends on the Asset
 * Volumes it can select from). Read-only from the outside once built - it
 * never mutates a Craft resource, only records references between the live
 * objects CraftResourceScanner already returned.
 *
 * Node identity is `"{kind}:{key}"`, where $key is a uid when the resource
 * has one and its handle otherwise (see ResourceNode). Both handle and uid
 * lookups are supported via separate indexes so callers can use whichever
 * they have on hand.
 */
final class ResourceGraph
{
    /** @var array<string, ResourceNode> "{kind}:{key}" => node */
    private array $nodes = [];

    /** @var array<string, string> "{kind}:{handle}" => "{kind}:{key}" */
    private array $handleIndex = [];

    /** @var array<string, string> "{kind}:{uid}" => "{kind}:{key}" */
    private array $uidIndex = [];

    /** @var array<string, array<string, true>> nodeKey => [dependencyNodeKey => true] ("depends on") */
    private array $edges = [];

    /** @var array<string, array<string, true>> nodeKey => [dependentNodeKey => true] (reverse of $edges) */
    private array $reverseEdges = [];

    public function addNode(ResourceNode $node, ?string $uid = null): void
    {
        $nodeKey = self::nodeKey($node->kind, $node->key);
        $this->nodes[$nodeKey] = $node;
        $this->handleIndex[self::nodeKey($node->kind, $node->handle)] = $nodeKey;
        if ($uid !== null) {
            $this->uidIndex[self::nodeKey($node->kind, $uid)] = $nodeKey;
        }
    }

    /**
     * Records that the resource identified by ($fromKind, $fromKey) depends
     * on ($toKind, $toKey) - e.g. addEdge('field', $assetsField->uid,
     * 'assetVolume', $volume->uid). Both ends must already have been added
     * via addNode(); an edge to/from an unknown node is silently ignored
     * (a reference to a resource this graph never discovered - e.g. a
     * Volume that's since been deleted - rather than a caller-facing error).
     */
    public function addEdge(string $fromKind, string $fromKey, string $toKind, string $toKey): void
    {
        $from = self::nodeKey($fromKind, $fromKey);
        $to = self::nodeKey($toKind, $toKey);
        if (!isset($this->nodes[$from]) || !isset($this->nodes[$to]) || $from === $to) {
            return;
        }
        $this->edges[$from][$to] = true;
        $this->reverseEdges[$to][$from] = true;
    }

    public function getByHandle(string $kind, string $handle): ?ResourceNode
    {
        $nodeKey = $this->handleIndex[self::nodeKey($kind, $handle)] ?? null;
        return $nodeKey !== null ? $this->nodes[$nodeKey] : null;
    }

    public function getByUid(string $kind, string $uid): ?ResourceNode
    {
        $nodeKey = $this->uidIndex[self::nodeKey($kind, $uid)] ?? null;
        return $nodeKey !== null ? $this->nodes[$nodeKey] : null;
    }

    /** @return ResourceNode[] every node of the given kind, insertion order */
    public function all(string $kind): array
    {
        return array_values(array_filter($this->nodes, fn(ResourceNode $n) => $n->kind === $kind));
    }

    /** @return ResourceNode[] the resources $node directly depends on */
    public function dependenciesOf(ResourceNode $node): array
    {
        return $this->resolveEdgeSet($this->edges[self::nodeKey($node->kind, $node->key)] ?? []);
    }

    /** @return ResourceNode[] the resources that directly depend on $node */
    public function dependentsOf(ResourceNode $node): array
    {
        return $this->resolveEdgeSet($this->reverseEdges[self::nodeKey($node->kind, $node->key)] ?? []);
    }

    /**
     * A dependency-first ordering of every node in the graph (Kahn's
     * algorithm): if A depends on B, B is guaranteed to appear before A.
     * Nodes with no dependencies (Asset Volumes, Category/Tag Groups,
     * Sections with no Entry Types, etc.) come first. A cycle (e.g. two
     * Matrix-nested Entry Types that reference each other as block types -
     * legal in Craft, since both are created together via Project Config
     * rather than sequentially) can't be fully ordered; any nodes still
     * unresolved once the acyclic part is exhausted are appended afterward
     * in their original insertion order rather than throwing, since a
     * best-effort order is more useful to a future installer than a hard
     * failure over something Craft itself allows.
     *
     * @return ResourceNode[]
     */
    public function topologicalOrder(): array
    {
        $remainingDependencyCount = [];
        foreach ($this->nodes as $nodeKey => $node) {
            $remainingDependencyCount[$nodeKey] = count($this->edges[$nodeKey] ?? []);
        }

        $queue = array_keys(array_filter($remainingDependencyCount, fn($count) => $count === 0));
        $ordered = [];
        $visited = [];

        while (!empty($queue)) {
            $nodeKey = array_shift($queue);
            if (isset($visited[$nodeKey])) {
                continue;
            }
            $visited[$nodeKey] = true;
            $ordered[] = $this->nodes[$nodeKey];

            foreach (array_keys($this->reverseEdges[$nodeKey] ?? []) as $dependentKey) {
                if (isset($visited[$dependentKey])) {
                    continue;
                }
                $remainingDependencyCount[$dependentKey]--;
                if ($remainingDependencyCount[$dependentKey] <= 0) {
                    $queue[] = $dependentKey;
                }
            }
        }

        foreach ($this->nodes as $nodeKey => $node) {
            if (!isset($visited[$nodeKey])) {
                $ordered[] = $node;
            }
        }

        return $ordered;
    }

    /** @param array<string, true> $edgeSet @return ResourceNode[] */
    private function resolveEdgeSet(array $edgeSet): array
    {
        $result = [];
        foreach (array_keys($edgeSet) as $nodeKey) {
            if (isset($this->nodes[$nodeKey])) {
                $result[] = $this->nodes[$nodeKey];
            }
        }
        return $result;
    }

    private static function nodeKey(string $kind, string $key): string
    {
        return $kind . ':' . $key;
    }
}
