<?php

namespace site7\studio\services\sitekit;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds a Full Site Kit: everything a fresh Craft install needs to become a
 * structural copy of this site - project config, exact Composer packages,
 * templates, modules, config files and frontend sources - plus, unless
 * left out, its content (SiteKitContent). See docs/48_FULL_SITE_KIT.md.
 *
 * Output: storage/site7-studio/site-kits/<handle>.zip
 *   site-kit.json   - manifest (see build())
 *   files/...       - paths relative to the project root
 */
class SiteKitBuilder extends Component
{
    /**
     * @return array{path: string, manifest: array}
     * @throws \Exception
     */
    public function build(string $name, bool $withContent = true): array
    {
        $root = rtrim(Craft::getAlias('@root'), '/');
        $handle = StringHelper::toKebabCase($name);
        $outDir = Craft::getAlias('@storage') . '/site7-studio/site-kits';
        FileHelper::createDirectory($outDir);
        $zipPath = "{$outDir}/{$handle}.zip";

        $composerJson = json_decode((string)file_get_contents("{$root}/composer.json"), true);
        if (!is_array($composerJson) || !is_file("{$root}/composer.lock")) {
            throw new \Exception('composer.json and composer.lock are both required.');
        }

        $installedPlugins = array_keys(array_filter(
            Craft::$app->getPlugins()->getAllPluginInfo(),
            fn(array $info) => !empty($info['isInstalled'])
        ));
        $projectYaml = Yaml::parseFile("{$root}/config/project/project.yaml");
        [$projectYaml, $stalePlugins] = SiteKitFiles::removeStalePlugins($projectYaml, $installedPlugins);

        // Built under a temporary name and renamed when complete, so a failed
        // build never leaves a broken .zip in the kits list.
        $partPath = "{$zipPath}.part";
        $zip = new \ZipArchive();
        if ($zip->open($partPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Could not create {$partPath}.");
        }

        try {
            $manifest = $this->fill($zip, $root, $handle, $name, $withContent, $composerJson, $projectYaml, $stalePlugins);
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($partPath);
            throw $e;
        }
        rename($partPath, $zipPath);

        return ['path' => $zipPath, 'manifest' => $manifest];
    }

    private function fill(\ZipArchive $zip, string $root, string $handle, string $name, bool $withContent, array $composerJson, array $projectYaml, array $stalePlugins): array
    {
        $fileCounts = [];
        $zip->addFile("{$root}/composer.json", 'files/composer.json');
        $zip->addFile("{$root}/composer.lock", 'files/composer.lock');

        $fileCounts['config/project'] = SiteKitFiles::addTree($zip, $root, 'config/project');
        $zip->addFromString('files/config/project/project.yaml', Yaml::dump($projectYaml, 20, 2));

        $configFiles = [];
        foreach (scandir("{$root}/config") ?: [] as $entry) {
            if ($entry[0] === '.' || !SiteKitFiles::isTravellingConfigFile($entry)) {
                continue;
            }
            if (is_dir("{$root}/config/{$entry}")) {
                SiteKitFiles::addTree($zip, $root, "config/{$entry}");
            } else {
                $zip->addFile("{$root}/config/{$entry}", "files/config/{$entry}");
            }
            $configFiles[] = $entry;
        }

        foreach (SiteKitFiles::CODE_DIRECTORIES as $directory) {
            $fileCounts[$directory] = SiteKitFiles::addTree($zip, $root, $directory);
        }

        $content = $withContent ? (new SiteKitContent())->export($zip) : null;

        $manifest = [
            'schemaVersion' => SiteKitFiles::SCHEMA_VERSION,
            'handle' => $handle,
            'name' => $name,
            'builtAt' => date(DATE_ATOM),
            'craftVersion' => Craft::$app->getVersion(),
            'plugins' => array_keys($projectYaml['plugins'] ?? []),
            'removedStalePlugins' => $stalePlugins,
            'pathRepositories' => SiteKitFiles::pathRepositories($composerJson),
            'configFiles' => $configFiles,
            'fileCounts' => $fileCounts,
            'envKeys' => is_file("{$root}/.env") ? SiteKitFiles::envKeys((string)file_get_contents("{$root}/.env")) : [],
            'content' => $content ? ['counts' => $content['counts'], 'skipped' => $content['skipped'], 'assetFiles' => $content['assetFiles']] : null,
        ];
        $zip->addFromString('site-kit.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        // Asset files are added from temp copies, which must outlive close().
        foreach ($content['tempFiles'] ?? [] as $tempFile) {
            @unlink($tempFile);
        }
        if (!empty($content['tempFiles'])) {
            @rmdir(dirname($content['tempFiles'][0]));
        }

        return $manifest;
    }
}
