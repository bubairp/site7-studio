<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;

/**
 * Captures the project's installable plugin dependency list (Website
 * Starter Kit System Phase 4) - distinct from
 * ResourceClassifierService's reporting-only `pluginDependencies` (which
 * only flags a missing plugin against one captured field). This is a
 * whole-project "what plugins does this site run" snapshot, not scoped to
 * any particular captured page/field.
 *
 * Craft's own `getComposerPluginInfo()` gives the *installed* plugin's own
 * package name, but not the version constraint declared in the project's
 * composer.json `require` - so this cross-references both: the plugin
 * registry for which Composer packages are actually Craft plugins, and the
 * project root's composer.json for the constraint string actually used.
 */
class ComposerDependencyScanner extends Component
{
    /**
     * This plugin's own handle - excluded from the captured list, since
     * whatever performs the install already needs Site7 Studio running to
     * do so; it is never itself a dependency to (re)install.
     */
    private const SELF_HANDLE = 'site7-studio';

    /**
     * @return array<int, array{handle: string, package: string, versionConstraint: string}>
     */
    public function captureComposerPluginDependencies(): array
    {
        $composerRequire = $this->readComposerRequire();

        $result = [];
        foreach (Craft::$app->getPlugins()->getComposerPluginInfo() ?? [] as $handle => $info) {
            if ($handle === self::SELF_HANDLE) {
                continue;
            }
            $packageName = $info['packageName'] ?? null;
            if (!$packageName) {
                continue;
            }
            $versionConstraint = $composerRequire[$packageName] ?? ('^' . ($info['version'] ?? '1.0.0'));
            $result[] = [
                'handle' => $handle,
                'package' => $packageName,
                'versionConstraint' => $versionConstraint,
            ];
        }

        return $result;
    }

    /** @return array<string, string> composer package name => version constraint, from the project's own require + require-dev */
    private function readComposerRequire(): array
    {
        $projectRoot = rtrim(dirname(Craft::$app->getPath()->getVendorPath()), '/\\');
        $composerJsonPath = $projectRoot . '/composer.json';
        if (!is_file($composerJsonPath)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($composerJsonPath), true);
        if (!is_array($data)) {
            return [];
        }

        return array_merge($data['require'] ?? [], $data['require-dev'] ?? []);
    }
}
