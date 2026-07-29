<?php

namespace site7\studio\tests\unit\models\registry;

use Codeception\Test\Unit;
use site7\studio\models\registry\ResourceGraph;
use site7\studio\models\registry\ResourceNode;

/**
 * Covers ResourceGraph's pure in-memory graph logic (Website Starter Kit
 * System CraftResourceRegistry refactor) - node/edge storage, lookup by
 * handle/uid, dependency traversal, and topological ordering. Nodes wrap
 * plain stdClass fakes rather than live Craft objects, so no Craft bootstrap
 * is needed here (matching this repo's existing convention of unit-testing
 * only the Craft-independent pieces).
 */
class ResourceGraphTest extends Unit
{
    protected \UnitTester $tester;

    private function node(string $kind, string $key, string $handle): ResourceNode
    {
        return new ResourceNode($kind, $key, $handle, ucfirst($handle), (object)['handle' => $handle]);
    }

    public function testGetByHandleAndGetByUidResolveTheSameNode()
    {
        $graph = new ResourceGraph();
        $node = $this->node('section', 'uid-1', 'blog');
        $graph->addNode($node, 'uid-1');

        $this->assertSame($node, $graph->getByHandle('section', 'blog'));
        $this->assertSame($node, $graph->getByUid('section', 'uid-1'));
    }

    public function testGetByHandleReturnsNullForAnUnknownResource()
    {
        $graph = new ResourceGraph();
        $this->assertNull($graph->getByHandle('section', 'nonexistent'));
        $this->assertNull($graph->getByUid('section', 'nonexistent'));
    }

    public function testAllFiltersByKindAndPreservesInsertionOrder()
    {
        $graph = new ResourceGraph();
        $section = $this->node('section', 'uid-1', 'blog');
        $entryType = $this->node('entryType', 'uid-2', 'article');
        $graph->addNode($section, 'uid-1');
        $graph->addNode($entryType, 'uid-2');

        $this->assertSame([$section], $graph->all('section'));
        $this->assertSame([$entryType], $graph->all('entryType'));
    }

    public function testDependenciesOfAndDependentsOfAreInverses()
    {
        $graph = new ResourceGraph();
        $section = $this->node('section', 'uid-1', 'blog');
        $entryType = $this->node('entryType', 'uid-2', 'article');
        $graph->addNode($section, 'uid-1');
        $graph->addNode($entryType, 'uid-2');
        $graph->addEdge('section', 'uid-1', 'entryType', 'uid-2');

        $this->assertSame([$entryType], $graph->dependenciesOf($section));
        $this->assertSame([$section], $graph->dependentsOf($entryType));
        $this->assertSame([], $graph->dependenciesOf($entryType));
        $this->assertSame([], $graph->dependentsOf($section));
    }

    public function testEdgeToAnUnknownNodeIsSilentlyIgnored()
    {
        $graph = new ResourceGraph();
        $section = $this->node('section', 'uid-1', 'blog');
        $graph->addNode($section, 'uid-1');

        // 'entryType':'missing' was never added via addNode().
        $graph->addEdge('section', 'uid-1', 'entryType', 'missing');

        $this->assertSame([], $graph->dependenciesOf($section));
    }

    public function testTopologicalOrderPutsDependenciesBeforeDependents()
    {
        $graph = new ResourceGraph();
        $volume = $this->node('assetVolume', 'uid-1', 'images');
        $field = $this->node('field', 'uid-2', 'heroImage');
        $entryType = $this->node('entryType', 'uid-3', 'article');
        $graph->addNode($volume, 'uid-1');
        $graph->addNode($field, 'uid-2');
        $graph->addNode($entryType, 'uid-3');

        // entryType depends on field, field depends on volume.
        $graph->addEdge('entryType', 'uid-3', 'field', 'uid-2');
        $graph->addEdge('field', 'uid-2', 'assetVolume', 'uid-1');

        $order = $graph->topologicalOrder();
        $positions = [];
        foreach ($order as $i => $node) {
            $positions[$node->handle] = $i;
        }

        $this->assertLessThan($positions['heroImage'], $positions['images']);
        $this->assertLessThan($positions['article'], $positions['heroImage']);
        $this->assertCount(3, $order);
    }

    public function testTopologicalOrderDegradesGracefullyOnACycle()
    {
        $graph = new ResourceGraph();
        $a = $this->node('entryType', 'uid-a', 'a');
        $b = $this->node('entryType', 'uid-b', 'b');
        $graph->addNode($a, 'uid-a');
        $graph->addNode($b, 'uid-b');

        // A depends on B and B depends on A - a cycle, legal in this graph.
        $graph->addEdge('entryType', 'uid-a', 'entryType', 'uid-b');
        $graph->addEdge('entryType', 'uid-b', 'entryType', 'uid-a');

        $order = $graph->topologicalOrder();

        // No exception, and both nodes still appear exactly once.
        $this->assertCount(2, $order);
        $handles = array_map(fn($n) => $n->handle, $order);
        sort($handles);
        $this->assertSame(['a', 'b'], $handles);
    }

    public function testAnalyzeCyclesReportsExactlyTheUnresolvedNodes()
    {
        $graph = new ResourceGraph();
        $volume = $this->node('assetVolume', 'uid-1', 'images');
        $a = $this->node('entryType', 'uid-a', 'a');
        $b = $this->node('entryType', 'uid-b', 'b');
        $graph->addNode($volume, 'uid-1');
        $graph->addNode($a, 'uid-a');
        $graph->addNode($b, 'uid-b');

        // volume has no dependencies (acyclic); a and b depend on each other (cyclic).
        $graph->addEdge('entryType', 'uid-a', 'entryType', 'uid-b');
        $graph->addEdge('entryType', 'uid-b', 'entryType', 'uid-a');

        $result = $graph->analyzeCycles();

        $this->assertCount(3, $result['ordered']);
        $cyclicHandles = array_map(fn($n) => $n->handle, $result['cyclic']);
        sort($cyclicHandles);
        $this->assertSame(['a', 'b'], $cyclicHandles);
    }

    public function testAnalyzeCyclesReportsNoCyclicNodesOnAnAcyclicGraph()
    {
        $graph = new ResourceGraph();
        $volume = $this->node('assetVolume', 'uid-1', 'images');
        $field = $this->node('field', 'uid-2', 'heroImage');
        $graph->addNode($volume, 'uid-1');
        $graph->addNode($field, 'uid-2');
        $graph->addEdge('field', 'uid-2', 'assetVolume', 'uid-1');

        $result = $graph->analyzeCycles();

        $this->assertSame([], $result['cyclic']);
    }

    public function testAllEdgesReturnsEveryEdgeAsResolvedNodePairs()
    {
        $graph = new ResourceGraph();
        $volume = $this->node('assetVolume', 'uid-1', 'images');
        $field = $this->node('field', 'uid-2', 'heroImage');
        $graph->addNode($volume, 'uid-1');
        $graph->addNode($field, 'uid-2');
        $graph->addEdge('field', 'uid-2', 'assetVolume', 'uid-1');

        $edges = $graph->allEdges();

        $this->assertCount(1, $edges);
        $this->assertSame($field, $edges[0]['from']);
        $this->assertSame($volume, $edges[0]['to']);
    }

    public function testAllEdgesExcludesEdgesToUnknownNodes()
    {
        $graph = new ResourceGraph();
        $section = $this->node('section', 'uid-1', 'blog');
        $graph->addNode($section, 'uid-1');
        $graph->addEdge('section', 'uid-1', 'entryType', 'missing');

        $this->assertSame([], $graph->allEdges());
    }
}
