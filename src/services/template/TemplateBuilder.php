<?php

namespace site7\studio\services\template;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use site7\studio\services\import\SectionSchemaService;
use site7\studio\services\sitekit\SiteKitContent;
use site7\studio\Site7Studio;

/**
 * Builds a Template package (format v2, docs/50_TEMPLATE_PACKAGE.md) from one
 * page of this site: the page's content exactly as stored, in the
 * SiteKitContent format, as a Library package in packages/template-<...>/:
 *
 *   manifest.json   type "template"; requires the Theme and the Section
 *                   packages of the blocks the page uses
 *   template.json   format version, the page (section, entry type, URI), counts
 *   content/        the page entry, its nested entries and the
 *                   assets/categories/tags they relate to; links.json holds
 *                   its relations to other pages
 *
 * The page's structure comes from the Theme and its blocks from Section
 * packages, so a Template carries content only.
 */
class TemplateBuilder extends Component
{
    public const META_FILE = 'template.json';
    public const FORMAT_VERSION = 2;
    public const HANDLE_PREFIX = 'template-';

    /**
     * @return array{handle: string, path: string, meta: array}
     * @throws \Exception
     */
    public function build(Entry $entry, ?string $themeHandle = null, string $version = '1.0.0'): array
    {
        if ($entry->getIsDraft() || $entry->getIsRevision() || $entry->getPrimaryOwnerId() !== null || !$entry->getSection()) {
            throw new \Exception("Entry #{$entry->id} isn't a page (a live entry of a section).");
        }
        $plugin = Site7Studio::getInstance();
        $pageBuilderUid = $plugin->getSettings()->matrixFieldUid;
        if (!$pageBuilderUid) {
            throw new \Exception('Configure the page-builder field in Site7 Studio Setup first.');
        }
        $themeHandle ??= self::libraryTheme();
        $section = $entry->getSection();
        $handle = self::handleFor($entry);
        $name = $section->type === 'single' ? (string)$entry->title : "{$section->name}: {$entry->title}";

        $dir = dirname(Craft::getAlias('@site7/studio')) . "/packages/{$handle}";
        $pricingType = \site7\studio\services\theme\ThemeBuilder::existingPricingType($dir);
        if (is_dir($dir)) {
            FileHelper::removeDirectory($dir);
        }
        FileHelper::createDirectory($dir);

        try {
            $content = $this->exportContent($dir, (int)$entry->id);
            $maxId = max(array_column(json_decode((string)file_get_contents("{$dir}/content/tables/elements.json"), true) ?: [['id' => 0]], 'id'));
            if ($maxId >= SiteKitContent::LIBRARY_ID_LIMIT) {
                throw new \Exception("Element #{$maxId} is above the Library's ID range (" . SiteKitContent::LIBRARY_ID_LIMIT . ').');
            }

            // Blocks the page uses - as exported, so leftovers (deleted
            // blocks) don't count - each from its Section package: the
            // page-builder blocks, which must be in the Library, and any
            // Library block nested deeper (a CTA Banner inside another
            // block), whose template the outer block includes.
            $blockTypeUids = [];
            $nestedTypeUids = [];
            foreach (json_decode((string)file_get_contents("{$dir}/content/tables/entries.json"), true) ?: [] as $row) {
                if (!is_string($row['typeId'] ?? null) || (int)$row['id'] === (int)$entry->id) {
                    continue;
                }
                if (($row['fieldId'] ?? null) === "@uid:{$pageBuilderUid}" && (int)$row['primaryOwnerId'] === (int)$entry->id) {
                    $blockTypeUids[] = substr($row['typeId'], 5);
                } else {
                    $nestedTypeUids[] = substr($row['typeId'], 5);
                }
            }
            $blockPackages = $this->libraryBlocks();
            $requiredSections = [];
            foreach (array_unique($blockTypeUids) as $uid) {
                if (!isset($blockPackages[$uid])) {
                    throw new \Exception("'{$entry->title}' uses the block '" . (Craft::$app->getEntries()->getEntryTypeByUid($uid)?->handle ?? $uid) . "', which isn't in the Library as a Section package.");
                }
                $requiredSections[] = $blockPackages[$uid];
            }
            foreach (array_unique($nestedTypeUids) as $uid) {
                if (isset($blockPackages[$uid])) {
                    $requiredSections[] = $blockPackages[$uid];
                }
            }
            $requiredSections = array_values(array_unique($requiredSections));
            sort($requiredSections);
        } catch (\Throwable $e) {
            FileHelper::removeDirectory($dir);
            throw $e;
        }

        $meta = [
            'formatVersion' => self::FORMAT_VERSION,
            'entryUid' => $entry->uid,
            'section' => $section->handle,
            'sectionUid' => $section->uid,
            'sectionType' => $section->type,
            'entryType' => $entry->getType()->handle,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'uri' => $entry->uri,
            'blocks' => count($blockTypeUids),
            'content' => $content,
        ];
        file_put_contents("{$dir}/" . self::META_FILE, $this->json($meta));

        file_put_contents("{$dir}/manifest.json", $this->json([
            'schemaVersion' => '1',
            'handle' => $handle,
            'name' => $name,
            'type' => 'template',
            'version' => $version,
            'author' => Craft::$app->getUser()->getIdentity()?->friendlyName ?? 'Site7',
            'description' => "The page \"{$entry->title}\" (/" . ($entry->uri === '__home__' ? '' : $entry->uri) . ') with its content: ' . count($requiredSections) . ' block types.',
            'category' => $section->name,
            'sourceSection' => $section->handle,
            'sourceSectionType' => $section->type,
            'sourceEntryType' => $entry->getType()->handle,
            'requires' => ['themes' => [$themeHandle], 'sections' => $requiredSections],
            'pricingType' => $pricingType,
        ]));
        file_put_contents("{$dir}/README.md", "# {$name}\n\nTemplate package built with `site7-studio/template/build`. See docs/50_TEMPLATE_PACKAGE.md.\n");

        $plugin->packageManager->discoverPackages();
        if ($record = $plugin->packageManager->getPackageByHandle($handle)) {
            $record->creatorId = Craft::$app->getUser()->getId()
                ?? \craft\elements\User::find()->admin(true)->status(null)->ids()[0] ?? null;
            $record->save(false);
        }

        return ['handle' => $handle, 'path' => $dir, 'meta' => $meta];
    }

    /**
     * Every page: live entries of sections with URLs.
     *
     * @return array{built: string[], errors: string[]}
     */
    public function buildAll(?string $themeHandle = null): array
    {
        $result = ['built' => [], 'errors' => []];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $hasUrls = false;
            foreach ($section->getSiteSettings() as $siteSettings) {
                $hasUrls = $hasUrls || $siteSettings->hasUrls;
            }
            if (!$hasUrls) {
                continue;
            }
            foreach (Entry::find()->sectionId($section->id)->status(null)->orderBy(['elements.id' => SORT_ASC])->all() as $entry) {
                try {
                    $result['built'][] = $this->build($entry, $themeHandle)['handle'];
                } catch (\Throwable $e) {
                    $result['errors'][] = "{$section->handle}/{$entry->slug}: {$e->getMessage()}";
                }
            }
        }

        return $result;
    }

    public static function handleFor(Entry $entry): string
    {
        $section = $entry->getSection();

        return self::handleFrom($section->handle, $section->type, $entry->slug);
    }

    /** template-<single section>, or template-<section>-<slug> */
    public static function handleFrom(string $sectionHandle, string $sectionType, ?string $slug): string
    {
        $parts = $sectionType === 'single' ? [$sectionHandle] : [$sectionHandle, (string)$slug];

        return self::HANDLE_PREFIX . implode('-', array_map(fn($part) => StringHelper::toKebabCase($part), $parts));
    }

    /** The Library's Theme package; there must be exactly one unless one is named. */
    public static function libraryTheme(): string
    {
        $themes = [];
        foreach (glob(dirname(Craft::getAlias('@site7/studio')) . '/packages/*/manifest.json') ?: [] as $file) {
            $manifest = json_decode((string)file_get_contents($file), true);
            if (($manifest['type'] ?? null) === 'theme') {
                $themes[] = $manifest['handle'];
            }
        }
        if (count($themes) !== 1) {
            throw new \Exception('The Library has ' . count($themes) . ' Theme packages - name the one this page belongs to.');
        }

        return $themes[0];
    }

    /** @return array<string, string> block entry type UID => Section package handle */
    private function libraryBlocks(): array
    {
        $blocks = [];
        foreach (glob(dirname(Craft::getAlias('@site7/studio')) . '/packages/*/' . SectionSchemaService::FILE) ?: [] as $file) {
            $schema = json_decode((string)file_get_contents($file), true);
            $manifest = json_decode((string)@file_get_contents(dirname($file) . '/manifest.json'), true);
            if (($manifest['type'] ?? null) === 'section' && !empty($schema['entryType'])) {
                $blocks[$schema['entryType']] = $manifest['handle'];
            }
        }

        return $blocks;
    }

    private function exportContent(string $dir, int $entryId): array
    {
        $zipPath = Craft::$app->getRuntimePath() . '/site7-template-content-' . StringHelper::randomString(6) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $result = (new SiteKitContent())->export($zip, null, false, [$entryId]);
        $zip->close();
        foreach ($result['tempFiles'] as $tempFile) {
            @unlink($tempFile);
        }

        $zip->open($zipPath);
        $zip->extractTo($dir);
        $zip->close();
        @unlink($zipPath);

        return ['counts' => $result['counts'], 'skipped' => $result['skipped'], 'assetFiles' => $result['assetFiles']];
    }

    private function json(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
