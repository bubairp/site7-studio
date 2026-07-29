<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use site7\studio\models\project\Project;
use site7\studio\services\import\WebsiteImportService;

/**
 * Assembles a complete Project (Website Starter Kit System Phase 5) from
 * the independent capture systems Phases 1-4 built: drives
 * WebsiteImportService for the actual capture (pages, globals,
 * craftSections, assetVolumes, categoryGroups, tagGroups, navigation,
 * Phase 4's whole-environment dependencies - all of that machinery is
 * reused unmodified, not re-implemented here), then adds the one piece no
 * existing service computes: a project-wide Platform Configuration summary
 * (Phase 3's PlatformConfigService, aggregated across every captured page
 * rather than noted per-field as ResourceClassifierService does today).
 *
 * This is the single orchestration point for Starter Kit generation that
 * StarterKitBuilder, DependencyAnalyzer, and BlueprintBuilder all consume -
 * none of them re-derive capture data themselves.
 */
class ProjectBuilder extends Component
{
    public ?WebsiteImportService $websiteImporter = null;

    public function init(): void
    {
        parent::init();
        $this->websiteImporter ??= new WebsiteImportService();
    }

    /**
     * @param int[] $entryIds
     * @param int[] $globalSetIds
     * @param array $meta {name, description?, category?, tags?, version?}
     * @throws \Exception if WebsiteImportService::importWebsite() throws (none of the given entries could be captured, validation failure, etc.)
     */
    public function build(array $entryIds, array $globalSetIds, array $meta): Project
    {
        [$record, $skipped, $notes] = $this->websiteImporter->importWebsite($entryIds, $globalSetIds, $meta);
        $manifest = $record->getManifest();

        return new Project(
            packageRecord: $record,
            manifest: $manifest,
            registry: $this->websiteImporter->registry,
            platformConfiguration: $this->computePlatformConfiguration($entryIds),
            skipped: $skipped,
            notes: $notes,
        );
    }

    /**
     * @param int[] $entryIds
     * @return array{categories: string[], fields: array<int, array{handle: string, name: string, category: string}>}
     */
    private function computePlatformConfiguration(array $entryIds): array
    {
        $fields = [];
        foreach (Entry::find()->id($entryIds)->status(null)->all() as $entry) {
            /** @var Entry $entry */
            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                $category = PlatformConfigService::categoryFor($field->handle);
                if ($category !== null) {
                    $fields[$field->handle] = ['handle' => $field->handle, 'name' => $field->name, 'category' => $category];
                }
            }
        }

        $fields = array_values($fields);
        $categories = array_values(array_unique(array_column($fields, 'category')));
        sort($categories);

        return ['categories' => $categories, 'fields' => $fields];
    }
}
