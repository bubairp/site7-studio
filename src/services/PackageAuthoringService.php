<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use site7\studio\Site7Studio;
use site7\studio\records\PackageRecord;

/**
 * Backs the Package Authoring Platform's New Package wizard and Package
 * Editor. Scaffolds a bare package skeleton (manifest.json + README +
 * preview folder) for any of the four package types and lets its General
 * metadata be edited afterward - both through the same workflow regardless
 * of type, per Phase 11's "one consistent workflow" objective.
 *
 * A freshly-created package has no Content yet (no matrix.yaml/fields.yaml/
 * template.twig for Sections, no requires/demoContent for the others) -
 * that's this milestone's explicit scope boundary (General + Package
 * Information only). It installs as an empty shell if enabled early, which
 * is exactly what its "draft" authoring status is meant to signal.
 */
class PackageAuthoringService extends Component
{
    public const VALID_TYPES = ['section', 'pattern', 'template', 'starter-kit'];

    /**
     * @param array $meta {type, name, handle?, description?, category?, tags?, version?, author?}
     * @throws \Exception if the type is unsupported or the handle is already taken.
     */
    public function createPackage(array $meta): PackageRecord
    {
        $type = strtolower((string)($meta['type'] ?? ''));
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \Exception('Unsupported package type.');
        }

        $name = trim((string)($meta['name'] ?? ''));
        if ($name === '') {
            throw new \Exception('A package name is required.');
        }

        $handle = trim((string)($meta['handle'] ?? '')) ?: StringHelper::toKebabCase($name);
        $basePath = Craft::getAlias('@packages');
        if (is_dir($basePath . '/' . $handle)) {
            throw new \Exception("A package with the handle '{$handle}' already exists.");
        }

        $packagePath = rtrim($basePath, '/') . '/' . $handle;
        FileHelper::createDirectory($packagePath);
        FileHelper::createDirectory($packagePath . '/preview');

        $tags = array_values(array_filter(array_map('trim', explode(',', (string)($meta['tags'] ?? '')))));

        $manifest = [
            'schemaVersion' => '1',
            'handle' => $handle,
            'name' => $name,
            'type' => $type,
            'version' => $meta['version'] ?? '1.0.0',
            'author' => $meta['author'] ?? (Craft::$app->getUser()->getIdentity()?->friendlyName ?? ''),
            'description' => $meta['description'] ?? '',
            'category' => $meta['category'] ?? null,
            'tags' => $tags,
            'requires' => [],
            'demoContent' => [],
            'pages' => [],
            'dependencies' => [],
        ];

        file_put_contents($packagePath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($packagePath . '/README.md', "# {$name}\n\nCreated via the Package Authoring Platform.\n");

        $packageManager = Site7Studio::getInstance()->packageManager;
        $packageManager->discoverPackages();

        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception('Package was created on disk but could not be registered.');
        }

        // Newly-authored packages start in Draft, distinct from the generic
        // discovery default ("published", for packages that already existed
        // on disk before this feature did - see the authoringStatus column's
        // own DB default and its migration).
        $record->authoringStatus = 'draft';
        $record->save();

        return $record;
    }

    /**
     * Updates a package's General metadata - both its manifest.json (the
     * portable, single-source-of-truth definition) and its PackageRecord
     * (the DB-side mirror the Library/Package Engine actually query).
     *
     * @param array $fields {name?, description?, category?, tags?, author?, version?}
     * @throws \Exception if the package can't be found.
     */
    public function updatePackage(string $handle, array $fields): PackageRecord
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception('Package not found.');
        }

        $packagePath = $packageManager->getPackagePath($handle);
        if (!$packagePath || !file_exists($packagePath . '/manifest.json')) {
            throw new \Exception('Package manifest not found on disk.');
        }

        $manifestData = json_decode(file_get_contents($packagePath . '/manifest.json'), true) ?: [];

        foreach (['name', 'description', 'category', 'author', 'version'] as $key) {
            if (array_key_exists($key, $fields)) {
                $manifestData[$key] = $fields[$key];
            }
        }
        if (array_key_exists('tags', $fields)) {
            $manifestData['tags'] = array_values(array_filter(array_map('trim', explode(',', (string)$fields['tags']))));
        }

        file_put_contents($packagePath . '/manifest.json', json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (isset($manifestData['name'])) {
            $record->name = $manifestData['name'];
        }
        if (isset($manifestData['description'])) {
            $record->description = $manifestData['description'];
        }
        if (isset($manifestData['category'])) {
            $record->category = $manifestData['category'];
        }
        if (isset($manifestData['author'])) {
            $record->author = $manifestData['author'];
        }
        if (isset($manifestData['version'])) {
            $record->version = $manifestData['version'];
        }
        if (isset($manifestData['tags'])) {
            $record->tags = implode(',', $manifestData['tags']);
        }
        $record->save();

        return $record;
    }
}
