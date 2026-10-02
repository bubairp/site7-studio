<?php

namespace site7\studio\services\sitekit;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Installs a Full Site Kit onto a FRESH Craft install (docs/48_FULL_SITE_KIT.md).
 *
 * Order matters, each lesson from the 2026-10-02 spike:
 * 1. Same Craft version as the source, and exact package versions from its
 *    composer.lock - project config refuses to apply across schema versions.
 * 2. Every plugin the kit's project config lists must be installable before
 *    applying it: a failed apply aborts, and Craft then rewrites
 *    config/project from the half-applied state, deleting the kit's files.
 * 3. The target keeps its own system/email settings.
 * 4. Missing .env keys are reported, never written.
 *
 * Steps after `composer install` run as subprocesses: this process still
 * has the old autoloader and plugin list loaded.
 */
class SiteKitInstaller extends Component
{
    private const TIMEOUT_SECONDS = 1200;

    /**
     * Pre-flight checks; nothing is written.
     *
     * @return array{errors: string[], warnings: string[], manifest: array|null, dir: string|null}
     */
    public function validateKit(string $zipPath): array
    {
        $result = ['errors' => [], 'warnings' => [], 'manifest' => null, 'dir' => null];

        try {
            [$manifest, $dir] = $this->extract($zipPath);
        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
            return $result;
        }
        $result['manifest'] = $manifest;
        $result['dir'] = $dir;
        $root = $this->root();

        if (($manifest['schemaVersion'] ?? null) !== SiteKitFiles::SCHEMA_VERSION) {
            $result['errors'][] = 'Unsupported site kit format version ' . ($manifest['schemaVersion'] ?? '?') . '.';
        }

        if (Craft::$app->getVersion() !== ($manifest['craftVersion'] ?? null)) {
            $result['errors'][] = "This kit was built on Craft {$manifest['craftVersion']}; this site runs Craft " . Craft::$app->getVersion() . '. Install the same Craft version first.';
        }

        $sections = count(Craft::$app->getEntries()->getAllSections());
        $entries = Entry::find()->status(null)->count();
        if ($sections > 0 || $entries > 0) {
            $result['errors'][] = "This site isn't fresh ({$sections} sections, {$entries} entries). A Full Site Kit replaces the whole site structure, so it only installs on a fresh Craft install.";
        }

        foreach ($manifest['pathRepositories'] ?? [] as $path) {
            if (!is_dir("{$root}/{$path}")) {
                $result['errors'][] = "Package source '{$path}' is a local folder in the source project and isn't on this site. Put it at {$path}/, or publish it to a Git repository the source project's composer.json points at.";
            }
        }

        if (!empty($manifest['content']) && !Craft::$app->getDb()->getIsMysql()) {
            $result['errors'][] = 'This kit contains content, which can only be imported into a MySQL database.';
        }

        if (!(new ExecutableFinder())->find('npm')) {
            $result['warnings'][] = 'npm was not found - the frontend will not be built.';
        }

        $targetEnvKeys = is_file("{$root}/.env") ? SiteKitFiles::envKeys((string)file_get_contents("{$root}/.env")) : [];
        $targetEnvKeys = array_merge($targetEnvKeys, array_keys(getenv()));
        $missing = SiteKitFiles::missingEnvKeys($manifest['envKeys'] ?? [], $targetEnvKeys);
        if ($missing) {
            $result['warnings'][] = 'Add these to .env (values are never copied): ' . implode(', ', $missing);
        }

        return $result;
    }

    /**
     * @param callable(string): void|null $log receives one line per step
     * @return array{errors: string[], warnings: string[], backup: string|null}
     */
    public function install(string $zipPath, ?callable $log = null): array
    {
        $log ??= fn(string $line) => null;
        $validation = $this->validateKit($zipPath);
        $result = ['errors' => $validation['errors'], 'warnings' => $validation['warnings'], 'backup' => null];
        if ($validation['errors']) {
            return $result;
        }

        $kit = $validation['dir'] . '/files';
        $root = $this->root();
        $php = App::phpExecutable() ?? 'php';

        // Loaded now: composer install below replaces vendor/ under this process.
        class_exists(Process::class);
        class_exists(Yaml::class);
        class_exists(FileHelper::class);

        $targetSettings = [];
        foreach (SiteKitFiles::TARGET_OWNED_CONFIG_KEYS as $key) {
            $targetSettings[$key] = Craft::$app->getProjectConfig()->get($key);
        }

        $result['backup'] = $this->backup($root);
        $log("Backed up composer files, config/ and templates/ to {$result['backup']}");

        // 1. Code and config files (project config comes last, step 4).
        foreach (SiteKitFiles::CODE_DIRECTORIES as $directory) {
            if (is_dir("{$kit}/{$directory}")) {
                if (is_dir("{$root}/{$directory}") && $directory === 'templates') {
                    FileHelper::removeDirectory("{$root}/{$directory}");
                }
                FileHelper::copyDirectory("{$kit}/{$directory}", "{$root}/{$directory}");
                $log("Copied {$directory}/");
            }
        }
        foreach (scandir("{$kit}/config") ?: [] as $entry) {
            if ($entry[0] === '.' || $entry === 'project') {
                continue;
            }
            is_dir("{$kit}/config/{$entry}")
                ? FileHelper::copyDirectory("{$kit}/config/{$entry}", "{$root}/config/{$entry}")
                : copy("{$kit}/config/{$entry}", "{$root}/config/{$entry}");
        }
        $log('Copied config files: ' . implode(', ', $validation['manifest']['configFiles'] ?? []));

        // 2. Exact packages.
        copy("{$kit}/composer.json", "{$root}/composer.json");
        copy("{$kit}/composer.lock", "{$root}/composer.lock");
        $composerPhar = Craft::$app->getRuntimePath() . '/composer.phar';
        copy(Craft::getAlias('@lib/composer.phar'), $composerPhar);
        if (!$this->run([$php, $composerPhar, 'install', '--no-interaction', '--no-scripts', '--working-dir=' . $root], $root, 'composer install', $result, $log)) {
            return $result;
        }

        // 3. Migrations for the packages just installed.
        if (!$this->run([$php, "{$root}/craft", 'migrate/all', '--interactive=0'], $root, 'craft migrate/all', $result, $log)) {
            return $result;
        }

        // 4. Project config - only once every listed plugin is installable.
        $available = $this->availablePluginHandles($root);
        $missingPlugins = array_values(array_diff($validation['manifest']['plugins'] ?? [], $available));
        if ($missingPlugins) {
            $result['errors'][] = 'Not applying project config: these plugins are not installed by composer: ' . implode(', ', $missingPlugins) . '. Applying anyway would abort half way and Craft would rewrite config/project.';
            return $result;
        }
        FileHelper::removeDirectory("{$root}/config/project");
        FileHelper::copyDirectory("{$kit}/config/project", "{$root}/config/project");
        $projectYaml = SiteKitFiles::keepTargetSettings(Yaml::parseFile("{$root}/config/project/project.yaml"), $targetSettings);
        file_put_contents("{$root}/config/project/project.yaml", Yaml::dump($projectYaml, 20, 2));
        if (!$this->run([$php, "{$root}/craft", 'project-config/apply', '--force'], $root, 'craft project-config/apply', $result, $log)) {
            return $result;
        }

        // 5. Content, in a process that has the kit's project config loaded.
        if (!empty($validation['manifest']['content'])
            && !$this->run([$php, "{$root}/craft", 'site7-studio/site-kit/import-content', $validation['dir']], $root, 'import content', $result, $log)) {
            return $result;
        }

        // 6. Frontend.
        $npm = (new ExecutableFinder())->find('npm');
        $frontend = "{$root}/frontend";
        if ($npm && is_file("{$frontend}/package.json")) {
            $this->run([$npm, is_file("{$frontend}/package-lock.json") ? 'ci' : 'install', '--no-audit', '--no-fund'], $frontend, 'npm install', $result, $log)
                && !empty(json_decode((string)file_get_contents("{$frontend}/package.json"), true)['scripts']['build'])
                && $this->run([$npm, 'run', 'build'], $frontend, 'npm run build', $result, $log);
        }

        return $result;
    }

    /**
     * @return array{0: array, 1: string} [site-kit.json, extracted directory]
     */
    private function extract(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if (!is_file($zipPath) || $zip->open($zipPath) !== true) {
            throw new \Exception("Could not open site kit {$zipPath}.");
        }
        $dir = Craft::getAlias('@storage') . '/runtime/site7-studio/site-kit/' . StringHelper::UUID();
        FileHelper::createDirectory($dir);
        $zip->extractTo($dir);
        $zip->close();

        $manifest = json_decode((string)@file_get_contents("{$dir}/site-kit.json"), true);
        if (!is_array($manifest)) {
            throw new \Exception('Not a site kit: site-kit.json is missing or invalid.');
        }

        return [$manifest, $dir];
    }

    private function backup(string $root): string
    {
        $dir = Craft::getAlias('@storage') . '/site7-studio/site-kit-backups/' . date('Ymd-His');
        FileHelper::createDirectory($dir);
        foreach (['composer.json', 'composer.lock'] as $file) {
            if (is_file("{$root}/{$file}")) {
                copy("{$root}/{$file}", "{$dir}/{$file}");
            }
        }
        foreach (['config', 'templates'] as $directory) {
            if (is_dir("{$root}/{$directory}")) {
                FileHelper::copyDirectory("{$root}/{$directory}", "{$dir}/{$directory}");
            }
        }

        return $dir;
    }

    /**
     * Plugin handles composer installed, read from Craft's plugin manifest
     * on disk (this process's plugin list predates composer install).
     *
     * @return string[]
     */
    private function availablePluginHandles(string $root): array
    {
        $file = "{$root}/vendor/craftcms/plugins.php";
        $plugins = is_file($file) ? (static fn() => require $file)() : [];

        return array_values(array_filter(array_map(fn($info) => $info['handle'] ?? null, is_array($plugins) ? $plugins : [])));
    }

    private function run(array $command, string $cwd, string $label, array &$result, callable $log): bool
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        if (!$process->isSuccessful()) {
            $output = trim($process->getErrorOutput() ?: $process->getOutput());
            $result['errors'][] = "{$label} failed: " . substr($output, -1500);
            $log("FAILED {$label}");
            return false;
        }

        $log("Done: {$label}");
        return true;
    }

    private function root(): string
    {
        return rtrim(Craft::getAlias('@root'), '/');
    }
}
