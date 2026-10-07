<?php

namespace site7\studio\services\template;

use Craft;
use craft\base\Component;
use site7\studio\records\PackageRecord;
use site7\studio\services\sitekit\SiteKitContent;
use site7\studio\Site7Studio;

/**
 * Installs a Template package's page (format v2, docs/50_TEMPLATE_PACKAGE.md).
 * Called from PackageManagerService::installPackage(): preflight() before
 * the required Section packages are installed, installContent() once they are.
 */
class TemplateInstaller extends Component
{
    public static function isFormatV2(?string $packagePath): bool
    {
        return $packagePath !== null && is_file("{$packagePath}/" . TemplateBuilder::META_FILE);
    }

    /**
     * Checks that don't depend on the required Section packages.
     *
     * @return string[] errors
     */
    public function preflight(PackageRecord $record): array
    {
        $errors = [];
        if (!Craft::$app->getDb()->getIsMysql()) {
            $errors[] = 'Template packages can only be installed on MySQL.';
        }
        $packageManager = Site7Studio::getInstance()->packageManager;
        foreach ($record->getManifest()?->requires['themes'] ?? [] as $themeHandle) {
            if ($packageManager->getPackageByHandle($themeHandle)?->status !== 'enabled') {
                $errors[] = "'{$record->name}' needs the Theme '{$themeHandle}' - install it first (site7-studio/theme/install).";
            }
        }
        if (!SiteKitContent::libraryIdsReserved()) {
            $errors[] = "This site wasn't set up from a Site7 Theme, so its own content could use the IDs this page's content keeps.";
        }

        return $errors;
    }

    /**
     * @return array<string, int> rows imported per table
     * @throws \Exception
     */
    public function installContent(string $packagePath): array
    {
        // Its section may be one of the Theme's optional ones (docs/49 §2c).
        $meta = json_decode((string)@file_get_contents("{$packagePath}/" . TemplateBuilder::META_FILE), true) ?: [];
        if (!empty($meta['section'])) {
            (new \site7\studio\services\theme\ThemeInstaller())->addSections([$meta['section']]);
        }
        $content = new SiteKitContent();
        if ($missing = $content->missingStructure($packagePath)) {
            throw new \Exception('This site is missing structure the page needs: ' . implode(', ', array_slice($missing, 0, 10)));
        }

        return $content->import($packagePath);
    }
}
