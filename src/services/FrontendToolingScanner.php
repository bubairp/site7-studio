<?php

namespace site7\studio\services;

use craft\base\Component;

/**
 * Detects a Craft project's frontend build tooling and npm dependency list
 * (Website Starter Kit System Phase 4). Purely filesystem-based - there is
 * no Craft resource/API surface for "what build system does this project
 * use," so this scanner reads config files directly rather than going
 * through CraftResourceScanner/CraftResourceRegistry (which are for native
 * Craft resources only).
 *
 * A Craft project's frontend tooling commonly lives in a dedicated
 * subdirectory (e.g. `frontend/`) rather than the project root, since the
 * root is Craft/Composer's own territory - so this scans a short list of
 * conventional candidate directories for a `package.json` rather than
 * assuming one location.
 */
class FrontendToolingScanner extends Component
{
    /** Directories checked, in order, for a package.json - '' means the project root itself. */
    private const CANDIDATE_FRONTEND_DIRS = ['', 'frontend', 'assets', 'theme'];

    /**
     * Config filenames that identify a build system, checked in priority
     * order - the first system with a matching file present wins
     * (a project can have more than one tool installed; this just picks
     * the primary one for manifest->dependencies.frontendTooling.system).
     */
    private const SYSTEM_CONFIG_FILES = [
        'vite' => ['vite.config.js', 'vite.config.mjs', 'vite.config.ts'],
        'webpack' => ['webpack.config.js', 'webpack.mix.js'],
        'gulp' => ['gulpfile.js'],
        'tailwind' => ['tailwind.config.js', 'tailwind.config.cjs', 'tailwind.config.ts'],
    ];

    /**
     * Every config file captured regardless of which system it belongs to -
     * union of every filename in SYSTEM_CONFIG_FILES plus these
     * build-adjacent ones (postcss/lint/browserslist config), so the
     * captured set is useful even when it doesn't determine `system`.
     */
    private const ADDITIONAL_CONFIG_FILES = [
        'postcss.config.js', 'postcss.config.cjs', 'postcss.config.mjs',
        '.browserslistrc',
        'eslint.config.js', '.eslintrc.js', '.eslintrc.json', '.eslintrc',
        'stylelint.config.js', '.stylelintrc.json', '.stylelintrc',
    ];

    /**
     * @return array{root: string, system: string, configFiles: string[]}|null
     * null if no package.json was found in any candidate directory under
     * the real Craft project's root.
     */
    public function detect(): ?array
    {
        return $this->detectAt($this->projectRoot());
    }

    /**
     * The pure detection logic, given a base path to search rather than
     * discovering the real project root via Craft::$app - extracted
     * specifically so it's unit-testable against a temp directory without a
     * live Craft app (see FrontendToolingScannerTest).
     *
     * @return array{root: string, system: string, configFiles: string[]}|null
     */
    public function detectAt(string $basePath): ?array
    {
        $root = $this->findFrontendRoot($basePath);
        if ($root === null) {
            return null;
        }

        $configFiles = [];
        $system = null;
        foreach (self::SYSTEM_CONFIG_FILES as $candidateSystem => $filenames) {
            foreach ($filenames as $filename) {
                if (is_file($root . '/' . $filename)) {
                    $configFiles[] = $filename;
                    $system ??= $candidateSystem;
                }
            }
        }
        foreach (self::ADDITIONAL_CONFIG_FILES as $filename) {
            if (is_file($root . '/' . $filename)) {
                $configFiles[] = $filename;
            }
        }

        $configFiles[] = 'package.json';

        // Tailwind v4 configures itself via CSS (@theme) and a Vite/PostCSS
        // plugin rather than a tailwind.config.* file, so a project on v4
        // never matches the SYSTEM_CONFIG_FILES entry above - fall back to
        // the package.json dependency itself.
        if ($system === null && $this->hasNpmDependency($root, 'tailwindcss')) {
            $system = 'tailwind';
        }

        return [
            'root' => $root,
            'system' => $system ?? 'plain',
            'configFiles' => array_values(array_unique($configFiles)),
        ];
    }

    /**
     * @return array<int, array{name: string, version: string, dev: bool}>
     */
    public function captureNpmDependencies(string $root): array
    {
        $data = $this->readJson($root . '/package.json');
        if ($data === null) {
            return [];
        }

        $result = [];
        foreach (($data['dependencies'] ?? []) as $name => $version) {
            $result[] = ['name' => (string)$name, 'version' => (string)$version, 'dev' => false];
        }
        foreach (($data['devDependencies'] ?? []) as $name => $version) {
            $result[] = ['name' => (string)$name, 'version' => (string)$version, 'dev' => true];
        }
        return $result;
    }

    /**
     * Lists candidate CSS/JS source files under $frontendRoot/src - Step
     * 8.1's Import Existing Section file-selection step reads this to show
     * a human what's available to explicitly opt in as package-owned.
     * DISCOVERY ONLY: nothing about being returned here makes a file
     * package-owned - that only happens when a caller explicitly includes
     * one of these paths in $meta['ownedFiles'] at import time (see
     * MatrixEntryTypeImportService::captureOwnedFiles()). No filename-to-
     * handle matching or other guessing happens here or anywhere else.
     *
     * @return string[] sorted, relative to the Craft project root (not to
     *   $frontendRoot) - the same convention PackageManifest::$ownedFiles'
     *   targetPath already uses, so a caller can use a returned path
     *   directly without re-deriving anything.
     */
    public function listCandidateFrontendFiles(string $frontendRoot): array
    {
        $srcDir = $frontendRoot . '/src';
        if (!is_dir($srcDir)) {
            return [];
        }

        $root = rtrim($this->projectRoot(), '/\\');
        $candidates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['css', 'js'], true)) {
                continue;
            }
            $candidates[] = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
        }

        sort($candidates);
        return $candidates;
    }

    private function findFrontendRoot(string $basePath): ?string
    {
        foreach (self::CANDIDATE_FRONTEND_DIRS as $candidate) {
            $path = $candidate === '' ? $basePath : $basePath . '/' . $candidate;
            if (is_file($path . '/package.json')) {
                return $path;
            }
        }
        return null;
    }

    /**
     * The Craft project's root directory (where composer.json lives) -
     * derived from the vendor path via the Craft API rather than a
     * project-specific bootstrap constant, so it works regardless of how a
     * given project's bootstrap.php happens to be written.
     */
    public function projectRoot(): string
    {
        return rtrim(dirname(\Craft::$app->getPath()->getVendorPath()), '/\\');
    }

    private function hasNpmDependency(string $root, string $packageName): bool
    {
        $data = $this->readJson($root . '/package.json');
        if ($data === null) {
            return false;
        }
        return isset($data['dependencies'][$packageName]) || isset($data['devDependencies'][$packageName]);
    }

    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }
}
