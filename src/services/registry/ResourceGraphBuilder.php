<?php

namespace site7\studio\services\registry;

use craft\base\FieldInterface;
use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\Matrix;
use craft\fields\Tags;
use site7\studio\models\registry\ResourceGraph;
use site7\studio\models\registry\ResourceNode;
use site7\studio\services\CraftResourceRegistry;
use site7\studio\services\CraftResourceScanner;
use site7\studio\services\scanning\RelationFieldSourceResolver;

/**
 * Builds the ResourceGraph CraftResourceRegistry serves, in one pass over
 * CraftResourceScanner's output. Single responsibility, kept out of
 * CraftResourceRegistry itself so the registry stays a thin
 * facade/session-cache (same split as CraftResourceScanner/its ten
 * sub-scanners) - a future consumer that needs a differently-shaped graph
 * can supply its own builder without touching the registry's public API.
 *
 * Edge direction throughout is "depends on": addEdge(A, B) means "A can't be
 * meaningfully installed/used without B already existing" - e.g. a Section
 * depends on its Entry Types, an Assets field depends on the Asset Volumes
 * it can select from. This is the direction ResourceGraph::topologicalOrder()
 * expects (dependencies first).
 */
class ResourceGraphBuilder
{
    public function __construct(private readonly CraftResourceScanner $scanner)
    {
    }

    public function build(): ResourceGraph
    {
        $graph = new ResourceGraph();

        $sections = $this->scanner->scanSections();
        $entryTypes = $this->scanner->scanEntryTypes();
        $fields = $this->scanner->scanFields();
        $volumes = $this->scanner->scanAssetVolumes();
        $categoryGroups = $this->scanner->scanCategoryGroups();
        $tagGroups = $this->scanner->scanTagGroups();
        $globalSets = $this->scanner->scanGlobalSets();
        $plugins = $this->scanner->scanPlugins();
        $navigationFields = $this->scanner->scanNavigation();

        foreach ($sections as $section) {
            $graph->addNode(new ResourceNode(CraftResourceRegistry::KIND_SECTION, $section->uid, $section->handle, $section->name, $section), $section->uid);
        }
        foreach ($entryTypes as $entryType) {
            $graph->addNode(new ResourceNode(CraftResourceRegistry::KIND_ENTRY_TYPE, $entryType->uid, $entryType->handle, $entryType->name, $entryType), $entryType->uid);
        }
        foreach ($fields as $field) {
            $graph->addNode(new ResourceNode(CraftResourceRegistry::KIND_FIELD, $field->uid, $field->handle, $field->name, $field), $field->uid);
        }
        foreach ($volumes as $volume) {
            $graph->addNode(new ResourceNode(CraftResourceRegistry::KIND_ASSET_VOLUME, $volume->uid, $volume->handle, $volume->name, $volume), $volume->uid);
        }
        foreach ($categoryGroups as $group) {
            $graph->addNode(new ResourceNode(CraftResourceRegistry::KIND_CATEGORY_GROUP, $group->uid, $group->handle, $group->name, $group), $group->uid);
        }
        foreach ($tagGroups as $group) {
            $graph->addNode(new ResourceNode(CraftResourceRegistry::KIND_TAG_GROUP, $group->uid, $group->handle, $group->name, $group), $group->uid);
        }
        foreach ($globalSets as $globalSet) {
            $graph->addNode(new ResourceNode(CraftResourceRegistry::KIND_GLOBAL_SET, $globalSet->uid, $globalSet->handle, $globalSet->name, $globalSet), $globalSet->uid);
        }
        foreach ($plugins as $plugin) {
            $graph->addNode(new ResourceNode(CraftResourceRegistry::KIND_PLUGIN, $plugin->handle, $plugin->handle, $plugin->name, $plugin));
        }
        foreach ($navigationFields as $field) {
            // A virtual node - Navigation has no Craft resource of its own,
            // it's a tag on whichever field a recognized nav plugin
            // provides. Keyed by the field's handle (fields are project-wide
            // unique by handle), edged to the real Field node below.
            $graph->addNode(new ResourceNode(CraftResourceRegistry::KIND_NAVIGATION, $field->handle, $field->handle, $field->name, $field));
        }

        foreach ($sections as $section) {
            foreach ($section->getEntryTypes() as $entryType) {
                $graph->addEdge(CraftResourceRegistry::KIND_SECTION, $section->uid, CraftResourceRegistry::KIND_ENTRY_TYPE, $entryType->uid);
            }
        }

        foreach ($entryTypes as $entryType) {
            $this->addFieldLayoutEdges($graph, CraftResourceRegistry::KIND_ENTRY_TYPE, $entryType->uid, $entryType->getFieldLayout()?->getCustomFields() ?? []);
        }
        foreach ($globalSets as $globalSet) {
            $this->addFieldLayoutEdges($graph, CraftResourceRegistry::KIND_GLOBAL_SET, $globalSet->uid, $globalSet->getFieldLayout()?->getCustomFields() ?? []);
        }

        foreach ($fields as $field) {
            $this->addFieldReferenceEdges($graph, $field);
        }

        foreach ($navigationFields as $field) {
            $graph->addEdge(CraftResourceRegistry::KIND_NAVIGATION, $field->handle, CraftResourceRegistry::KIND_FIELD, $field->uid);
        }

        return $graph;
    }

    /** @param FieldInterface[] $customFields */
    private function addFieldLayoutEdges(ResourceGraph $graph, string $ownerKind, string $ownerUid, array $customFields): void
    {
        foreach ($customFields as $field) {
            $graph->addEdge($ownerKind, $ownerUid, CraftResourceRegistry::KIND_FIELD, $field->uid);
        }
    }

    private function addFieldReferenceEdges(ResourceGraph $graph, FieldInterface $field): void
    {
        if ($field instanceof Assets) {
            foreach (RelationFieldSourceResolver::resolveUids($field->sources, 'volume:', fn() => $this->scanner->scanAssetVolumes()) as $uid) {
                $graph->addEdge(CraftResourceRegistry::KIND_FIELD, $field->uid, CraftResourceRegistry::KIND_ASSET_VOLUME, $uid);
            }
            return;
        }
        if ($field instanceof Categories) {
            foreach (RelationFieldSourceResolver::resolveUids($field->sources, 'group:', fn() => $this->scanner->scanCategoryGroups()) as $uid) {
                $graph->addEdge(CraftResourceRegistry::KIND_FIELD, $field->uid, CraftResourceRegistry::KIND_CATEGORY_GROUP, $uid);
            }
            return;
        }
        if ($field instanceof Tags) {
            foreach (RelationFieldSourceResolver::resolveUids($field->sources, 'taggroup:', fn() => $this->scanner->scanTagGroups()) as $uid) {
                $graph->addEdge(CraftResourceRegistry::KIND_FIELD, $field->uid, CraftResourceRegistry::KIND_TAG_GROUP, $uid);
            }
            return;
        }
        if ($field instanceof Entries) {
            foreach (RelationFieldSourceResolver::resolveUids($field->sources, 'section:', fn() => $this->scanner->scanSections()) as $uid) {
                $graph->addEdge(CraftResourceRegistry::KIND_FIELD, $field->uid, CraftResourceRegistry::KIND_SECTION, $uid);
            }
            return;
        }
        if ($field instanceof Matrix) {
            foreach ($field->getEntryTypes() as $entryType) {
                $graph->addEdge(CraftResourceRegistry::KIND_FIELD, $field->uid, CraftResourceRegistry::KIND_ENTRY_TYPE, $entryType->uid);
            }
        }
    }
}
