<?php

namespace site7\studio\services;

use craft\base\Component;
use site7\studio\models\project\Project;

/**
 * The top-level entry point for Starter Kit generation (Website Starter Kit
 * System Phase 5): orchestrates ProjectBuilder -> DependencyAnalyzer ->
 * BlueprintBuilder, writes the resulting Blueprint alongside the package's
 * own manifest.json, and validates the finished package.
 *
 * Scope is strictly build-only, per this phase's own boundary: nothing here
 * installs a plugin, runs Composer, runs npm, executes a frontend build, or
 * touches a target Craft installation. It produces a package on disk and
 * nothing more - that's Phase 6+'s job.
 */
class StarterKitBuilder extends Component
{
    public ?ProjectBuilder $projectBuilder = null;
    public ?DependencyAnalyzer $dependencyAnalyzer = null;
    public ?BlueprintBuilder $blueprintBuilder = null;

    public function init(): void
    {
        parent::init();
        $this->projectBuilder ??= new ProjectBuilder();
        $this->dependencyAnalyzer ??= new DependencyAnalyzer();
        $this->blueprintBuilder ??= new BlueprintBuilder();
    }

    /**
     * @param int[] $entryIds
     * @param int[] $globalSetIds
     * @param array $meta {name, description?, category?, tags?, version?}
     * @return array{project: Project, blueprint: array}
     * @throws \Exception if the underlying capture (ProjectBuilder/WebsiteImportService) fails.
     */
    public function build(array $entryIds, array $globalSetIds, array $meta): array
    {
        $project = $this->projectBuilder->build($entryIds, $globalSetIds, $meta);
        $plan = $this->dependencyAnalyzer->analyze($project);
        $blueprint = $this->blueprintBuilder->build($project, $plan);

        file_put_contents(
            $project->packagePath() . '/blueprint.json',
            json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return ['project' => $project, 'blueprint' => $blueprint];
    }
}
