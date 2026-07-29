<?php

namespace site7\studio\services\installation;

use craft\base\Component;
use site7\studio\models\installation\InstallationPlan;
use site7\studio\models\installation\InstallationStep;
use site7\studio\services\ComposerDependencyScanner;

/**
 * Turns a Blueprint (Website Starter Kit System Phase 5) into a
 * deterministic, ordered InstallationPlan (Phase 6) - a flat list of
 * InstallationSteps InstallationExecutor can run one at a time. This class
 * only ever *reads* the target environment (is this package already
 * required in composer.json, is this plugin already installed/enabled) to
 * decide which steps are actually needed; it never installs, runs, or
 * mutates anything itself.
 *
 * Step order is fixed and reflects real dependency order: a plugin's code
 * must be pulled in by Composer before Craft can install/enable it; Craft
 * resources (Volumes/Category-Tag Groups/Section settings) must exist
 * before content referencing them is installed; frontend config/npm
 * install happens last since nothing else depends on it; Project Config is
 * rebuilt at the very end so every element-level save above is reflected
 * in config/project/ before the CP's "Apply pending changes" banner would
 * otherwise appear.
 */
class InstallationPlanner extends Component
{
    public ?ComposerDependencyScanner $composerScanner = null;

    public function init(): void
    {
        parent::init();
        $this->composerScanner ??= new ComposerDependencyScanner();
    }

    /**
     * @param array $blueprint BlueprintBuilder::build()'s output.
     * @param string $packagePath the Starter Kit package's own directory on disk (for its copied frontend/ files).
     */
    public function plan(array $blueprint, string $packagePath): InstallationPlan
    {
        $errors = [];
        $warnings = [];
        $steps = [];

        if (empty($blueprint['packageHandle'])) {
            $errors[] = 'Blueprint is missing its packageHandle - cannot plan an installation.';
            return new InstallationPlan([], ['errors' => $errors, 'warnings' => $warnings]);
        }

        [$composerSteps, $pluginSteps, $depWarnings] = $this->planPlugins($blueprint['requiredPlugins'] ?? []);
        $steps = array_merge($steps, $composerSteps, $pluginSteps);
        $warnings = array_merge($warnings, $depWarnings);

        $steps = array_merge($steps, self::planCraftResources($blueprint['resources'] ?? []));
        $steps = array_merge($steps, self::planContent($blueprint['packageHandle']));
        $steps = array_merge($steps, self::planFrontend($blueprint['frontendRequirements'] ?? [], $packagePath));
        $steps[] = new InstallationStep(InstallationStep::TYPE_PROJECT_CONFIG, 'project-config-rebuild', 'Rebuild Project Config', []);

        return new InstallationPlan($steps, ['errors' => $errors, 'warnings' => $warnings]);
    }

    /**
     * @param array<int, array{handle: string, package: string, versionConstraint: string}> $requiredPlugins
     * @return array{0: InstallationStep[], 1: InstallationStep[], 2: string[]} [composer steps, plugin-install steps, dependency-validation warnings]
     */
    private function planPlugins(array $requiredPlugins): array
    {
        $composerSteps = [];
        $pluginSteps = [];
        $warnings = [];

        foreach ($requiredPlugins as $plugin) {
            $handle = $plugin['handle'] ?? null;
            $package = $plugin['package'] ?? null;
            if (!$handle || !$package) {
                continue;
            }

            if ($this->composerScanner->isPackageRequired($package)) {
                $warnings[] = "{$package} is already required in the target's composer.json - no composer step needed.";
            } else {
                $composerSteps[] = new InstallationStep(
                    InstallationStep::TYPE_COMPOSER,
                    $package,
                    "Require {$package} {$plugin['versionConstraint']}",
                    ['package' => $package, 'versionConstraint' => $plugin['versionConstraint'] ?? '*']
                );
            }

            $pluginSteps[] = new InstallationStep(
                InstallationStep::TYPE_PLUGIN_INSTALL,
                $handle,
                "Install and enable the \"{$handle}\" plugin",
                ['handle' => $handle, 'package' => $package]
            );
        }

        return [$composerSteps, $pluginSteps, $warnings];
    }

    /**
     * Static and Craft-app-independent by design (pure array shaping, no
     * target-environment reads) - directly unit-testable without a live
     * Craft app, unlike planPlugins() (see InstallationPlannerTest).
     *
     * @param array{craftSections: array, assetVolumes: array, categoryGroups: array, tagGroups: array} $resources
     * @return InstallationStep[]
     */
    public static function planCraftResources(array $resources): array
    {
        $steps = [];
        foreach (($resources['assetVolumes'] ?? []) as $volume) {
            $steps[] = new InstallationStep(InstallationStep::TYPE_CRAFT_RESOURCE, "assetVolume:{$volume['handle']}", "Create/update Asset Volume \"{$volume['name']}\"", ['kind' => 'assetVolume', 'data' => $volume]);
        }
        foreach (($resources['categoryGroups'] ?? []) as $group) {
            $steps[] = new InstallationStep(InstallationStep::TYPE_CRAFT_RESOURCE, "categoryGroup:{$group['handle']}", "Create/update Category Group \"{$group['name']}\"", ['kind' => 'categoryGroup', 'data' => $group]);
        }
        foreach (($resources['tagGroups'] ?? []) as $group) {
            $steps[] = new InstallationStep(InstallationStep::TYPE_CRAFT_RESOURCE, "tagGroup:{$group['handle']}", "Create/update Tag Group \"{$group['name']}\"", ['kind' => 'tagGroup', 'data' => $group]);
        }
        foreach (($resources['craftSections'] ?? []) as $section) {
            $steps[] = new InstallationStep(InstallationStep::TYPE_CRAFT_RESOURCE, "craftSection:{$section['handle']}", "Apply Section settings for \"{$section['name']}\"", ['kind' => 'craftSection', 'data' => $section]);
        }
        return $steps;
    }

    /** @return InstallationStep[] */
    public static function planContent(string $packageHandle): array
    {
        return [new InstallationStep(InstallationStep::TYPE_CONTENT, $packageHandle, 'Install captured pages and Global Set values', ['packageHandle' => $packageHandle])];
    }

    /** @return InstallationStep[] */
    public static function planFrontend(array $frontendRequirements, string $packagePath): array
    {
        $steps = [];
        $frontendTooling = $frontendRequirements['frontendTooling'] ?? [];
        $configFiles = $frontendTooling['configFiles'] ?? [];
        $npmPackages = $frontendRequirements['npmPackages'] ?? [];

        if (!empty($configFiles) && is_dir($packagePath . '/frontend')) {
            $steps[] = new InstallationStep(
                InstallationStep::TYPE_FRONTEND,
                'frontend-config',
                'Copy frontend build configuration into place',
                ['sourceDir' => $packagePath . '/frontend', 'configFiles' => $configFiles]
            );
        }
        if (!empty($npmPackages)) {
            $steps[] = new InstallationStep(
                InstallationStep::TYPE_NPM,
                'npm-install',
                'Install ' . count($npmPackages) . ' npm package(s)',
                ['packageCount' => count($npmPackages)]
            );
        }

        return $steps;
    }
}
