<?php

namespace site7\studio\services;

use craft\base\Component;
use site7\studio\models\project\InstallationPlan;
use site7\studio\models\project\Project;

/**
 * Turns a Project + its InstallationPlan into a Blueprint (Website Starter
 * Kit System Phase 5) - a plain, JSON-serializable installation-focused
 * document, deliberately independent of the final package format: nothing
 * here assumes a `.s7pkg`/manifest.json shape, so a future installer or a
 * different package format could consume the same Blueprint unchanged.
 *
 * Every value is derived from data StarterKitBuilder's callers already have
 * (Project's manifest, InstallationPlan) or from the package's own copied
 * files (frontend/package.json's scripts) - this class computes nothing
 * about live Craft state itself.
 */
class BlueprintBuilder extends Component
{
    public const SCHEMA_VERSION = '1';

    public function build(Project $project, InstallationPlan $plan): array
    {
        $manifest = $project->manifest;

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'packageHandle' => $manifest->handle,
            'packageName' => $manifest->name,
            'resources' => [
                'craftSections' => $manifest->craftSections,
                'assetVolumes' => $manifest->assetVolumes,
                'categoryGroups' => $manifest->categoryGroups,
                'tagGroups' => $manifest->tagGroups,
                'globalSets' => array_map(
                    fn($g) => ['handle' => $g['globalSetHandle'], 'name' => $g['name']],
                    $manifest->globals
                ),
                'pages' => array_map(
                    fn($p) => ['title' => $p['title'], 'slug' => $p['slug'], 'templateHandle' => $p['templateHandle']],
                    $manifest->pages
                ),
                'navigation' => array_map(
                    fn($n) => ['fieldHandle' => $n['fieldHandle'], 'ownerType' => $n['ownerType'], 'source' => $n['source']],
                    $manifest->navigation
                ),
            ],
            'dependencyRelationships' => $plan->dependencyRelationships,
            'installationOrder' => $plan->waves,
            'requiredPlugins' => $manifest->dependencies['plugins'] ?? [],
            'frontendRequirements' => [
                'npmPackages' => $manifest->dependencies['npmPackages'] ?? [],
                'frontendTooling' => $manifest->dependencies['frontendTooling'] ?? ['system' => null, 'configFiles' => []],
            ],
            'buildRequirements' => $this->buildRequirements($project),
            'platformConfiguration' => $project->platformConfiguration,
            'validation' => $this->validatePackage($project, $plan),
        ];
    }

    /**
     * Reads `scripts` off the package's own copied frontend/package.json
     * (not the original source project's) - the Blueprint should describe
     * exactly what's inside this .s7pkg, not depend on the source project
     * still being in the same state later.
     */
    private function buildRequirements(Project $project): array
    {
        $frontendTooling = $project->manifest->dependencies['frontendTooling'] ?? [];
        $scripts = [];

        $packageJsonPath = $project->packagePath() . '/frontend/package.json';
        if (is_file($packageJsonPath)) {
            $data = json_decode((string)file_get_contents($packageJsonPath), true);
            $scripts = is_array($data) ? ($data['scripts'] ?? []) : [];
        }

        return [
            'packageManager' => 'npm',
            'system' => $frontendTooling['system'] ?? null,
            'scripts' => $scripts,
        ];
    }

    /**
     * @return array{valid: bool, errors: string[], warnings: string[]}
     */
    private function validatePackage(Project $project, InstallationPlan $plan): array
    {
        $manifest = $project->manifest;
        $errors = [];
        $warnings = [];

        if (empty($manifest->pages)) {
            $errors[] = 'No pages captured - this package has no content to install.';
        }
        foreach ($manifest->pages as $page) {
            if (empty($page['templateHandle'])) {
                $errors[] = "Page \"{$page['title']}\" has no Template package reference.";
            }
        }
        if (empty($manifest->handle) || empty($manifest->name)) {
            $errors[] = 'Manifest is missing a handle or name.';
        }

        if (!empty($plan->cyclicResources)) {
            $warnings[] = count($plan->cyclicResources) . ' resource(s) participate in a circular dependency and could not be fully ordered - see cyclicResources.';
        }
        if (empty($manifest->dependencies['frontendTooling']['configFiles'] ?? [])) {
            $warnings[] = 'No frontend tooling detected - this package includes no frontend build configuration.';
        }
        if (!empty($project->skipped)) {
            $warnings[] = count($project->skipped) . ' page(s)/dependency reference(s) were skipped during capture - see the package skip log.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
