<?php

namespace site7\studio\services\installation;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use Symfony\Component\Process\ExecutableFinder;
use site7\studio\models\installation\InstallationPlan;
use site7\studio\models\installation\ValidationResult;
use site7\studio\services\ComposerDependencyScanner;

/**
 * Pure read-only pre-flight checks for a Starter Kit install (Website
 * Starter Kit System Phase 6) - dry-run by construction, since nothing here
 * ever writes anything. Can be run standalone against a Blueprint alone (a
 * "would this succeed" report before even building a plan) or alongside an
 * already-built InstallationPlan to fold in the planner's own dependency
 * findings.
 */
class InstallationValidator extends Component
{
    /** Minimum PHP version this plugin's own installation code relies on (typed properties, readonly, first-class callable syntax). */
    private const MIN_PHP_VERSION = '8.1.0';

    public ?ComposerDependencyScanner $composerScanner = null;

    public function init(): void
    {
        parent::init();
        $this->composerScanner ??= new ComposerDependencyScanner();
    }

    public function validateInstallation(array $blueprint, ?InstallationPlan $plan = null): ValidationResult
    {
        $errors = [];
        $warnings = [];
        $checks = [];

        $this->checkBlueprintIntegrity($blueprint, $errors, $checks);
        $this->checkRequiredPlugins($blueprint['requiredPlugins'] ?? [], $warnings, $checks);
        $this->checkComposerAvailability($errors, $checks);
        $this->checkNpmAvailability($blueprint, $warnings, $checks);
        $this->checkEnvironment($errors, $checks);
        $this->checkFilesystemPrerequisites($errors, $checks);

        if ($plan !== null) {
            $errors = array_merge($errors, $plan->dependencyValidation['errors'] ?? []);
            $warnings = array_merge($warnings, $plan->dependencyValidation['warnings'] ?? []);
        }

        return new ValidationResult(empty($errors), $errors, $warnings, $checks);
    }

    private function checkBlueprintIntegrity(array $blueprint, array &$errors, array &$checks): void
    {
        $requiredKeys = ['schemaVersion', 'packageHandle', 'packageName', 'resources', 'installationOrder', 'requiredPlugins', 'frontendRequirements', 'validation'];
        foreach ($requiredKeys as $key) {
            $passed = array_key_exists($key, $blueprint);
            $checks[] = ['name' => "blueprint.{$key} present", 'passed' => $passed, 'detail' => $passed ? 'OK' : "Missing required Blueprint key '{$key}'."];
            if (!$passed) {
                $errors[] = "Blueprint is missing required key '{$key}'.";
            }
        }

        if (($blueprint['validation']['valid'] ?? true) === false) {
            $errors[] = 'Blueprint itself failed validation at build time: ' . implode('; ', $blueprint['validation']['errors'] ?? []);
        }
    }

    /** @param array<int, array{handle: string, package: string, versionConstraint: string}> $requiredPlugins */
    private function checkRequiredPlugins(array $requiredPlugins, array &$warnings, array &$checks): void
    {
        $pluginsService = Craft::$app->getPlugins();
        foreach ($requiredPlugins as $plugin) {
            $handle = $plugin['handle'] ?? null;
            if (!$handle) {
                continue;
            }
            $installed = $pluginsService->isPluginInstalled($handle);
            $enabled = $installed && $pluginsService->isPluginEnabled($handle);
            $detail = $enabled
                ? 'Already installed and enabled.'
                : ($installed ? 'Installed but not enabled.' : 'Not installed - will require a Composer + plugin-install step.');
            $checks[] = ['name' => "plugin '{$handle}' availability", 'passed' => true, 'detail' => $detail];
            if (!$enabled) {
                $warnings[] = "Plugin '{$handle}': {$detail}";
            }
        }
    }

    /**
     * Craft ships its own bundled composer.phar (vendor/craftcms/cms/lib),
     * run via the official Craft::$app->getComposer() service - "is
     * Composer available" here means "is that bundled phar present and a
     * PHP executable resolvable," not a system-wide `composer` binary.
     */
    private function checkComposerAvailability(array &$errors, array &$checks): void
    {
        $pharPath = Craft::getAlias('@lib/composer.phar');
        $phpExecutable = App::phpExecutable();
        $passed = is_file($pharPath) && $phpExecutable !== null;

        $checks[] = [
            'name' => 'Composer availability',
            'passed' => $passed,
            'detail' => $passed
                ? "Craft's bundled composer.phar and a PHP executable are both available."
                : "Craft's bundled composer.phar or a PHP executable could not be found.",
        ];
        if (!$passed) {
            $errors[] = 'Composer is not available in this environment - plugin installation would fail.';
        }
    }

    private function checkNpmAvailability(array $blueprint, array &$warnings, array &$checks): void
    {
        $needsNpm = !empty($blueprint['frontendRequirements']['npmPackages'] ?? []);
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
            $warnings[] = 'npm was not found in PATH - frontend dependency installation will be skipped.';
        }
    }

    private function checkEnvironment(array &$errors, array &$checks): void
    {
        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=');
        $checks[] = ['name' => 'PHP version', 'passed' => $phpOk, 'detail' => 'Running PHP ' . PHP_VERSION . " (minimum " . self::MIN_PHP_VERSION . ')'];
        if (!$phpOk) {
            $errors[] = 'PHP ' . self::MIN_PHP_VERSION . '+ is required.';
        }
    }

    private function checkFilesystemPrerequisites(array &$errors, array &$checks): void
    {
        $composerJsonPath = $this->composerScanner->projectRoot() . '/composer.json';
        $writable = is_writable($composerJsonPath);
        $checks[] = [
            'name' => 'composer.json writable',
            'passed' => $writable,
            'detail' => $writable ? 'OK' : "{$composerJsonPath} is not writable.",
        ];
        if (!$writable) {
            $errors[] = 'composer.json is not writable - plugin installation would fail.';
        }
    }
}
