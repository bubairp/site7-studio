<?php

namespace site7\studio\services;

use craft\base\Component;
use site7\studio\models\registry\ResourceGraph;
use site7\studio\models\registry\ResourceNode;
use site7\studio\services\registry\ResourceGraphBuilder;

/**
 * Sits between CraftResourceScanner and every higher-level service (Website
 * Starter Kit System architecture): the Scanner only ever *discovers* live
 * Craft resources with no memory between calls; this Registry stores what
 * was discovered for the current import/build session, indexes it for
 * lookup by uid/handle/type, and resolves the cross-resource references
 * (which Entry Types a Section has, which Asset Volumes a field can select
 * from, etc.) into a single graph. Future systems (Project Builder,
 * Dependency Analyzer, Blueprint Builder, Starter Kit Builder, Starter Kit
 * Installer, Sync Engine) should ask this Registry for a resource or its
 * relationships rather than calling CraftResourceScanner - or Craft - on
 * their own; that keeps relationship-resolution logic (and its cost - a full
 * scan) in exactly one place.
 *
 * Read-only: nothing here ever mutates a Craft resource, and the graph is
 * only as fresh as the last getGraph()/reset() - call reset() (or
 * getGraph(force: true)) if Craft resources may have changed since this
 * Registry was last built within the same request/session.
 *
 * Named getGraph() rather than the more obvious "load" - craft\base\Component
 * (which every service in this plugin extends) inherits from
 * yii\base\Model, which already declares a load($data, $formName) method for
 * populating a model from POST data; naming this one load() as well would
 * silently break that unrelated, incompatible signature.
 */
class CraftResourceRegistry extends Component
{
    public const KIND_SECTION = 'section';
    public const KIND_ENTRY_TYPE = 'entryType';
    public const KIND_FIELD = 'field';
    public const KIND_ASSET_VOLUME = 'assetVolume';
    public const KIND_CATEGORY_GROUP = 'categoryGroup';
    public const KIND_TAG_GROUP = 'tagGroup';
    public const KIND_GLOBAL_SET = 'globalSet';
    public const KIND_NAVIGATION = 'navigation';
    public const KIND_PLUGIN = 'plugin';

    public ?CraftResourceScanner $scanner = null;

    private ?ResourceGraph $graph = null;

    public function init(): void
    {
        parent::init();
        $this->scanner ??= new CraftResourceScanner();
    }

    /**
     * Builds (or returns the already-built) resource graph for this
     * session. Craft is only actually scanned once per Registry instance,
     * unless $force is true.
     */
    public function getGraph(bool $force = false): ResourceGraph
    {
        if ($this->graph === null || $force) {
            $this->graph = (new ResourceGraphBuilder($this->scanner))->build();
        }
        return $this->graph;
    }

    /** Discards the cached graph so the next call re-scans Craft. */
    public function reset(): void
    {
        $this->graph = null;
    }

    public function findByHandle(string $kind, string $handle): ?ResourceNode
    {
        return $this->getGraph()->getByHandle($kind, $handle);
    }

    public function findByUid(string $kind, string $uid): ?ResourceNode
    {
        return $this->getGraph()->getByUid($kind, $uid);
    }

    /** @return ResourceNode[] every discovered resource of the given kind */
    public function all(string $kind): array
    {
        return $this->getGraph()->all($kind);
    }

    /** @return ResourceNode[] the resources $node directly depends on */
    public function dependenciesOf(ResourceNode $node): array
    {
        return $this->getGraph()->dependenciesOf($node);
    }

    /** @return ResourceNode[] the resources that directly depend on $node */
    public function dependentsOf(ResourceNode $node): array
    {
        return $this->getGraph()->dependentsOf($node);
    }

    /**
     * A dependency-first ordering of every discovered resource - the data
     * model a future Blueprint Builder/Starter Kit Installer needs to decide
     * what must exist before what. See ResourceGraph::topologicalOrder() for
     * the cycle-handling caveat.
     *
     * @return ResourceNode[]
     */
    public function installOrder(): array
    {
        return $this->getGraph()->topologicalOrder();
    }
}
