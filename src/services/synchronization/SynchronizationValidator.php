<?php

namespace site7\studio\services\synchronization;

use Composer\Semver\Semver;
use Craft;
use craft\base\Component;
use Symfony\Component\Process\ExecutableFinder;
use site7\studio\models\synchronization\SynchronizationPlan;
use site7\studio\models\synchronization\SynchronizationValidationResult;
use site7\studio\services\scanning\PluginScanner;

/**
 * Pure read-only compatibility checks for a synchronization run (Website
 * Starter Kit System Phase 8) - dry-run by construction, mirroring Phase 6's
 * InstallationValidator in shape ({valid, errors, warnings, checks}) but
 * asking sync-specific questions: is the target's actual Craft version
 * still within the new package's stated minimum, does every
 * already-installed plugin's actual version still satisfy what the new
 * Blueprint requires, is Project Config already mid-drift independent of
 * this sync, is npm available when frontend changes are proposed. Never
 * mutates anything.
 */
class SynchronizationValidator extends Component
{
    public ?PluginScanner $pluginScanner = null;

    public function init(): void
    {
        parent::init();
        $this->pluginScanner ??= new PluginScanner();
    }

    public function validateSynchronization(SynchronizationPlan $plan, ?string $minimumCraftVersion = null): SynchronizationValidationResult
    {
        $errors = [];
        $warnings = [];
        $checks = [];

        $this->checkCraftVersion($minimumCraftVersion, $errors, $checks);
        $this->checkPluginCompatibility($plan, $errors, $warnings, $checks);
        $this->checkProjectConfigState($checks, $warnings);
        $this->checkFrontendAvailability($plan, $warnings, $checks);

        if (!empty($plan->conflicts)) {
            $warnings[] = count($plan->conflicts) . ' resource(s) require manual review before this synchronization can fully apply - see the plan\'s conflicts.';
        }
        if (!empty($plan->errors)) {
            $errors = array_merge($errors, $plan->errors);
        }

        return new SynchronizationValidationResult(empty($errors), $errors, $warnings, $checks);
    }

    private function checkCraftVersion(?string $minimumCraftVersion, array &$errors, array &$checks): void
    {
        if ($minimumCraftVersion === null) {
            return;
        }

        $currentVersion = Craft::$app->getVersion();
        $passed = version_compare($currentVersion, $minimumCraftVersion, '>=');
        $checks[] = [
            'name' => 'Craft CMS version',
            'passed' => $passed,
            'detail' => "Running Craft {$currentVersion} (new Blueprint requires {$minimumCraftVersion}+).",
        ];
        if (!$passed) {
            $errors[] = "This site is running Craft {$currentVersion}, but the new Blueprint requires {$minimumCraftVersion}+.";
        }
    }

    private function checkPluginCompatibility(SynchronizationPlan $plan, array &$errors, array &$warnings, array &$checks): void
    {
        foreach ($plan->pluginChanges['added'] ?? [] as $plugin) {
            $checks[] = [
                'name' => "plugin '{$plugin['handle']}' (new requirement)",
                'passed' => true,
                'detail' => "Not yet installed - will be required and installed at {$plugin['versionConstraint']}.",
            ];
        }

        foreach ($plan->pluginChanges['updated'] ?? [] as $change) {
            $installedPlugin = $this->pluginScanner->findByHandle($change['handle']);
            $installedVersion = $installedPlugin?->version;
            $satisfies = $installedVersion !== null && $this->satisfiesConstraint($installedVersion, $change['to']);

            $checks[] = [
                'name' => "plugin '{$change['handle']}' version constraint",
                'passed' => $satisfies,
                'detail' => $installedVersion === null
                    ? 'Currently installed version could not be determined.'
                    : "Installed version {$installedVersion} " . ($satisfies ? "still satisfies {$change['to']}." : "does NOT satisfy the new constraint {$change['to']} - update composer.json manually."),
            ];
            if (!$satisfies) {
                $warnings[] = "Plugin '{$change['handle']}' may need a manual composer update - installed version doesn't satisfy the new constraint.";
            }
        }

        foreach ($plan->pluginChanges['removed'] ?? [] as $plugin) {
            $warnings[] = "The new Blueprint no longer requires '{$plugin['handle']}' - it will be left installed (this engine never uninstalls a plugin automatically).";
        }
    }

    private function satisfiesConstraint(string $version, ?string $constraint): bool
    {
        if (!$constraint) {
            return true;
        }
        try {
            return Semver::satisfies($version, $constraint);
        } catch (\Throwable $e) {
            return true; // an unparsable constraint isn't this validator's job to reject - surfaced informationally only
        }
    }

    private function checkProjectConfigState(array &$checks, array &$warnings): void
    {
        $pending = Craft::$app->getProjectConfig()->areChangesPending();
        $checks[] = [
            'name' => 'Project Config state',
            'passed' => !$pending,
            'detail' => $pending ? 'Project Config already has pending changes, independent of this synchronization.' : 'No pending Project Config changes.',
        ];
        if ($pending) {
            $warnings[] = 'Apply or discard the existing pending Project Config changes before running this synchronization.';
        }
    }

    private function checkFrontendAvailability(SynchronizationPlan $plan, array &$warnings, array &$checks): void
    {
        $needsNpm = !empty($plan->frontendChanges['npmAdded']) || !empty($plan->frontendChanges['npmUpdated']);
        if (!$needsNpm) {
            return;
        }

        $npmPath = (new ExecutableFinder())->find('npm');
        $checks[] = [
            'name' => 'npm availability',
            'passed' => $npmPath !== null,
            'detail' => $npmPath !== null ? "Found at {$npmPath}." : 'npm executable not found in PATH.',
        ];
        if ($npmPath === null) {
            $warnings[] = 'npm was not found in PATH - frontend dependency updates will be skipped.';
        }
    }
}
