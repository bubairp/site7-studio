<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\web\UploadedFile;
use site7\studio\Site7Studio;
use site7\studio\records\PackageRecord;

/**
 * Generates a Starter Kit package from a set of existing Entries ("Save Current
 * Site as Starter Kit"). Phase 10 scope: Pages + Templates only - Navigation,
 * Globals, Categories, Assets, and SEO are deferred to later increments.
 *
 * A Starter Kit never stores page content itself - each selected Entry is run
 * through the existing TemplateGeneratorService (the same "Save as Template"
 * path used elsewhere), and the Starter Kit's manifest records only a reference
 * to the resulting Template handle plus the page's own structural identity
 * (title/slug/section/entry type). Installing a Starter Kit replays that list
 * through the existing Create-from-Template mechanism.
 */
class StarterKitGeneratorService extends Component
{
    /**
     * @param Entry[] $entries The Entries to capture as pages.
     * @param array $meta {name, description, version?, category, tags, previewImage?: UploadedFile}
     * @return array{0: PackageRecord, 1: string[]} [the new Starter Kit package, per-entry skip reasons]
     * @throws \Exception if none of the given entries could be captured.
     */
    public function generateFromEntries(array $entries, array $meta): array
    {
        $templateGenerator = new TemplateGeneratorService();

        $pages = [];
        $requiresTemplates = [];
        $skipped = [];

        foreach ($entries as $entry) {
            try {
                $templateRecord = $templateGenerator->generateFromEntry($entry, [
                    'name' => $entry->title,
                    'description' => 'Captured from "' . $entry->title . '" as part of the "' . $meta['name'] . '" Starter Kit.',
                    'category' => $meta['category'] ?? '',
                    'tags' => '',
                ]);
            } catch (\Throwable $e) {
                $skipped[] = $entry->title . ': ' . $e->getMessage();
                continue;
            }

            $pages[] = [
                'title' => $entry->title,
                'slug' => $entry->slug,
                'sectionHandle' => $entry->getSection()?->handle,
                'entryTypeHandle' => $entry->getType()->handle,
                'templateHandle' => $templateRecord->handle,
            ];
            $requiresTemplates[] = $templateRecord->handle;
        }

        if (empty($pages)) {
            throw new \Exception('None of the selected pages have Site7 content, so no Templates could be captured.');
        }

        $handle = $this->generateUniqueHandle($meta['name']);
        $packagePath = rtrim(Craft::getAlias('@packages'), '/') . '/' . $handle;
        FileHelper::createDirectory($packagePath);

        $tags = array_values(array_filter(array_map('trim', explode(',', (string)($meta['tags'] ?? '')))));

        $manifest = [
            'schemaVersion' => '1',
            'handle' => $handle,
            'name' => $meta['name'],
            'type' => 'starter-kit',
            'version' => $meta['version'] ?? '1.0.0',
            'author' => $meta['author'] ?? (Craft::$app->getUser()->getIdentity()?->friendlyName ?? 'Site7'),
            'description' => $meta['description'] ?? '',
            'category' => $meta['category'] ?? null,
            'tags' => $tags,
            'requires' => array_filter([
                'templates' => array_values(array_unique($requiresTemplates)),
            ]),
            'pages' => $pages,
            'dependencies' => [],
        ];

        file_put_contents($packagePath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($packagePath . '/README.md', $this->buildReadme($meta['name'], $pages));

        FileHelper::createDirectory($packagePath . '/preview');

        /** @var UploadedFile|null $previewImage */
        $previewImage = $meta['previewImage'] ?? null;
        if ($previewImage instanceof UploadedFile && $previewImage->tempName) {
            copy($previewImage->tempName, $packagePath . '/preview/preview.png');
        }

        $packageManager = Site7Studio::getInstance()->packageManager;
        $packageManager->discoverPackages();
        $packageManager->installPackage($handle);
        $packageManager->enablePackage($handle);

        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception('Starter Kit was generated but could not be registered.');
        }

        return [$record, $skipped];
    }

    private function generateUniqueHandle(string $name): string
    {
        $base = StringHelper::toKebabCase($name);
        $handle = $base;
        $basePath = Craft::getAlias('@packages');
        $suffix = 2;
        while (is_dir($basePath . '/' . $handle)) {
            $handle = $base . '-' . $suffix;
            $suffix++;
        }
        return $handle;
    }

    private function buildReadme(string $name, array $pages): string
    {
        $list = implode("\n", array_map(fn($p) => "- {$p['title']} ({$p['templateHandle']})", $pages));
        return "# {$name}\n\nGenerated via \"Save Current Site as Starter Kit\".\n\nPages:\n\n{$list}\n";
    }
}
