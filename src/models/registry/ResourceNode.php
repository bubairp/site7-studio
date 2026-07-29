<?php

namespace site7\studio\models\registry;

/**
 * One native Craft resource inside a ResourceGraph - a thin wrapper around
 * the live Craft object CraftResourceScanner returned, never a copy of its
 * data. CraftResourceRegistry is the only thing that constructs these; a
 * consumer reads $resource to get back the real Section/EntryType/Volume/
 * etc. instance and call whatever Craft API it needs on it directly.
 */
final class ResourceNode
{
    /**
     * @param string $kind One of CraftResourceRegistry::KIND_* .
     * @param string $key The identity this node is stored/looked-up under - the resource's uid when it has one, its handle otherwise (Plugins have no uid).
     * @param string $handle
     * @param string $name
     * @param object $resource The live Craft object (Section, EntryType, FieldInterface, Volume, CategoryGroup, TagGroup, GlobalSet, PluginInterface).
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $key,
        public readonly string $handle,
        public readonly string $name,
        public readonly object $resource,
    ) {
    }
}
