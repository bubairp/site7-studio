<?php

namespace site7\studio\services\import;

use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\Assets;
use site7\studio\records\PackageRecord;
use site7\studio\repositories\PageImportSourceRepository;
use site7\studio\services\support\AssetCaptureHelper;
use site7\studio\Site7Studio;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 9.2's Update Package workflow for Page packages - mirrors
 * SectionUpdateService (Phase 9.1) exactly in shape/rationale. An imported
 * Page package is a read-only mirror of a live Craft Entry (see
 * PackageAuthoringService::isLockedImportedPage()); re-importing it is never
 * allowed, so this is the only way its content can change once imported.
 *
 * TemplateGeneratorService (the frozen "Save as Template" engine
 * PageImportService delegates to for Site7-content pages) is never called
 * or modified here - this service independently re-reads the same live data
 * it already reads (matrix block composition, entry type -> Section
 * package map) using the same, simple technique
 * TemplateGeneratorService::buildEntryTypeToSectionMap() uses (scan every
 * installed Section package's matrix.yaml), so it never has to touch that
 * frozen class's private methods. requires.sections stays a flat list of
 * Section handles on update (a requires.patterns entry left by an older
 * version, if any, is left untouched and ignored).
 */
class PageUpdateService
{
    /**
     * @return array{addedKeys: string[], removedKeys: string[], changedKeys: string[], unchangedKeys: string[]}
     * @throws \Exception if the package isn't an imported Page, or its source Entry no longer exists.
     */
    public function diff(string $packageHandle): array
    {
        // copyAssetFiles: false - a diff is a read-only preview and must
        // never mutate the package's files on disk; only updateInPlace()
        // actually copies a changed asset selection's bytes.
        [, , $manifestData, $recaptured] = $this->resolve($packageHandle, false);

        return $this->compare($manifestData, $recaptured);
    }

    /**
     * Stored content vs. recaptured content, key by key.
     *
     * @return array{addedKeys: string[], removedKeys: string[], changedKeys: string[], unchangedKeys: string[]}
     */
    private function compare(array $manifestData, array $recaptured): array
    {
        $oldContent = array_merge((array)($manifestData['entryFields'] ?? []), (array)($manifestData['demoContent'] ?? []));
        $newContent = array_merge($recaptured['entryFields'], $recaptured['demoContent']);

        $added = [];
        $removed = [];
        $changed = [];
        $unchanged = [];

        foreach ($newContent as $key => $value) {
            if (!array_key_exists($key, $oldContent)) {
                $added[] = $key;
            } elseif ($this->normalize($oldContent[$key]) !== $this->normalize($value)) {
                $changed[] = $key;
            } else {
                $unchanged[] = $key;
            }
        }
        foreach ($oldContent as $key => $value) {
            if (!array_key_exists($key, $newContent)) {
                $removed[] = $key;
            }
        }

        return ['addedKeys' => $added, 'removedKeys' => $removed, 'changedKeys' => $changed, 'unchangedKeys' => $unchanged];
    }

    /**
     * Rewrites entryFields/demoContent/requires from the live Entry's
     * current content, in place - never touches handle/name/type/
     * author/category/tags/description. Preserves the package's DB id and
     * every existing reference.
     *
     * Same guarantees as SectionUpdateService (docs/18): when nothing
     * changed it's a true no-op (no file written, no asset copied, no
     * version), and a change produces exactly one version through
     * VersionManagerService::createVersion() - a semver bump with an
     * archive, so it can be rolled back.
     *
     * @throws \Exception if the package isn't an imported Page, or its source Entry no longer exists.
     */
    public function updateInPlace(string $packageHandle): PackageRecord
    {
        // Decide first with a read-only recapture (no asset files copied).
        [$record, , $manifestData, $recaptured] = $this->resolve($packageHandle, false);
        $diff = $this->compare($manifestData, $recaptured);
        $requiresChanged = $this->normalize(array_values((array)($manifestData['requires']['sections'] ?? [])))
            !== $this->normalize(array_values((array)($recaptured['requires']['sections'] ?? [])));
        if (!$diff['addedKeys'] && !$diff['removedKeys'] && !$diff['changedKeys'] && !$requiresChanged) {
            return $record;
        }

        [$record, $entry, $manifestData, $recaptured, $sourceRecord] = $this->resolve($packageHandle, true);

        $packageManager = Site7Studio::getInstance()->packageManager;
        $packagePath = $packageManager->getPackagePath($packageHandle);
        if (!$packagePath) {
            throw new \Exception('Package not found on disk.');
        }

        $manifestData['entryFields'] = $recaptured['entryFields'];
        $manifestData['demoContent'] = $recaptured['demoContent'];
        $manifestData['requires'] = $recaptured['requires'];
        if (isset($recaptured['dependencies'])) {
            $manifestData['dependencies'] = $recaptured['dependencies'];
        }
        if (isset($recaptured['excludedFields'])) {
            $manifestData['excludedFields'] = $recaptured['excludedFields'];
        }

        $sourceHash = (new EntrySourceHasher())->computeHash($entry);
        $manifestData['importedFrom']['sourceHash'] = $sourceHash;
        $manifestData['importedFrom']['importedAt'] = date('c');

        file_put_contents($packagePath . '/manifest.json', json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Idempotent - the source Entry already exists, so this only syncs
        // any newly-referenced Section blocks' fields into the package's
        // dependency records.
        $packageManager->discoverPackages();
        $packageManager->installPackage($packageHandle);

        (new PageImportSourceRepository())->record($record->id, $entry->uid, (string)$entry->slug, $sourceHash);

        // Content added or removed is a shape change (minor); edited values
        // are a patch - the same rule SectionUpdateService uses.
        $bumpType = ($diff['addedKeys'] || $diff['removedKeys']) ? 'minor' : 'patch';
        $parts = array_filter([
            $diff['addedKeys'] ? count($diff['addedKeys']) . ' added' : null,
            $diff['removedKeys'] ? count($diff['removedKeys']) . ' removed' : null,
            $diff['changedKeys'] ? count($diff['changedKeys']) . ' changed' : null,
            $requiresChanged ? 'block list changed' : null,
        ]);
        Site7Studio::getInstance()->versionManager->createVersion(
            $packageHandle,
            $bumpType,
            "Synced from the live Craft page '{$entry->title}': " . implode(', ', $parts) . '.',
        );

        $record->refresh();

        return $record;
    }

    /**
     * @return array{0: PackageRecord, 1: Entry, 2: array, 3: array{entryFields: array, demoContent: array, requires: array, dependencies?: array, excludedFields?: array}, 4: \site7\studio\records\PageImportSourceRecord}
     * @throws \Exception if the package isn't an imported Page, or its source Entry no longer exists.
     */
    private function resolve(string $packageHandle, bool $copyAssetFiles = false): array
    {
        $record = Site7Studio::getInstance()->packageManager->getPackageByHandle($packageHandle);
        if (!$record || $record->type !== 'template') {
            throw new \Exception('This package is not a Page/Template.');
        }

        $sourceRecord = (new PageImportSourceRepository())->findByPackageId($record->id);
        if (!$sourceRecord) {
            throw new \Exception('This Template was not produced by Import Existing Page - there is no live source to update from.');
        }

        $entry = Entry::find()->uid($sourceRecord->sourceUid)->status(null)->one();
        if (!$entry instanceof Entry) {
            throw new \Exception("The source page '{$sourceRecord->sourceHandle}' no longer exists in this Craft project.");
        }

        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($packageHandle);
        if (!$packagePath || !file_exists($packagePath . '/manifest.json')) {
            throw new \Exception('Package manifest not found on disk.');
        }
        $manifestData = json_decode(file_get_contents($packagePath . '/manifest.json'), true) ?: [];

        $recaptured = $this->recapture($entry, $copyAssetFiles ? $packagePath : null);

        return [$record, $entry, $manifestData, $recaptured, $sourceRecord];
    }

    /**
     * @param string|null $packagePath Non-null (and only then) actually
     *   copies a captured Assets field's file(s) into the package - see
     *   PageImportService::captureNativeFields()'s own $packagePath
     *   docblock. Left null by diff()'s read-only preview.
     * @return array{entryFields: array, demoContent: array, requires: array, dependencies?: array, excludedFields?: array}
     */
    private function recapture(Entry $entry, ?string $packagePath = null): array
    {
        $matrixHandle = $this->getMatrixFieldHandle();
        $hasSite7Content = false;
        if ($matrixHandle && $entry->getFieldLayout()?->getFieldByHandle($matrixHandle)) {
            $fieldValue = $entry->getFieldValue($matrixHandle);
            $hasSite7Content = $fieldValue && $fieldValue->status(null)->drafts(null)->savedDraftsOnly(false)->count() > 0;
        }

        if (!$hasSite7Content) {
            [, $entryFields, $sharedResourceHandles, $pluginDependencies, $excludedFields] = (new PageImportService())->captureNativeFields($entry, $matrixHandle, $packagePath);
            return [
                'entryFields' => $entryFields,
                'demoContent' => [],
                'requires' => [],
                'dependencies' => [
                    'sharedResources' => array_values(array_unique($sharedResourceHandles)),
                    'pluginDependencies' => $pluginDependencies,
                ],
                'excludedFields' => $excludedFields,
            ];
        }

        $entryTypeToSection = $this->buildEntryTypeToSectionMap();
        $fieldValue = $entry->getFieldValue($matrixHandle);
        $blocks = $fieldValue->status(null)->drafts(null)->savedDraftsOnly(false)->all();

        $sectionHandles = [];
        $demoContent = [];
        foreach ($blocks as $block) {
            $entryTypeHandle = $block->getType()->handle;
            $sectionHandle = $entryTypeToSection[$entryTypeHandle] ?? null;
            if (!$sectionHandle) {
                continue;
            }
            $sectionHandles[] = $sectionHandle;
            $demoContent[$sectionHandle] = $this->captureValues($block, [], $packagePath);
        }

        // Same shape as the import (TemplateGeneratorService::generateFromEntry()):
        // a Section may appear more than once on a page, so no de-duplication.
        return [
            'entryFields' => $this->captureValues($entry, [$matrixHandle], $packagePath),
            'demoContent' => $demoContent,
            'requires' => array_filter(['sections' => $sectionHandles]),
        ];
    }

    /**
     * A block's or entry's field values exactly as the import captures them
     * (TemplateGeneratorService::extractFieldValues(), private in that frozen
     * class): Assets as a descriptor (files copied only when $packagePath is
     * given), relation queries skipped - their __toString() is the query's
     * class name, not content - and other non-stringable objects omitted.
     * EntrySourceHasher::extractScalarFieldValues() isn't used here: it
     * keeps those class names, and changing it would change every stored
     * source hash.
     */
    private function captureValues(Entry $element, array $skipHandles, ?string $packagePath): array
    {
        $values = [];
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (in_array($field->handle, $skipHandles, true)) {
                continue;
            }
            $value = $element->getFieldValue($field->handle);
            if ($field instanceof Assets) {
                $descriptor = AssetCaptureHelper::captureAssetField($value, $packagePath, $packagePath !== null);
                if ($descriptor !== null) {
                    $values[$field->handle] = $descriptor;
                }
                continue;
            }
            if ($value instanceof ElementQueryInterface) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $values[$field->handle] = $value;
            } elseif (is_object($value) && method_exists($value, '__toString')) {
                $values[$field->handle] = (string)$value;
            }
        }

        return $values;
    }

    /**
     * Same technique as TemplateGeneratorService::buildEntryTypeToSectionMap()
     * (independently reimplemented, not called - that class is frozen):
     * scans every installed Section package's matrix.yaml for its
     * [entryTypeHandle => sectionPackageHandle] mapping.
     *
     * @return array<string, string>
     */
    private function buildEntryTypeToSectionMap(): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $map = [];

        foreach ($packageManager->getAllPackages() as $pkg) {
            if (strtolower($pkg->type) !== 'section') {
                continue;
            }
            $path = $packageManager->getPackagePath($pkg->handle);
            if (!$path) {
                continue;
            }
            $matrixYamlPath = $path . '/matrix.yaml';
            if (!file_exists($matrixYamlPath)) {
                continue;
            }
            $matrixData = Yaml::parseFile($matrixYamlPath);
            $entryTypeHandle = $matrixData['blocks'][0]['handle'] ?? null;
            if ($entryTypeHandle) {
                $map[$entryTypeHandle] = $pkg->handle;
            }
        }

        return $map;
    }

    /** JSON with object keys sorted, so key order never counts as a change. */
    private function normalize(mixed $value): string
    {
        $sort = function($v) use (&$sort) {
            if (!is_array($v)) {
                return $v;
            }
            if (!array_is_list($v)) {
                ksort($v);
            }
            return array_map($sort, $v);
        };

        return json_encode($sort($value));
    }

    private function getMatrixFieldHandle(): ?string
    {
        $settings = Site7Studio::getInstance()->getSettings();
        if (!$settings->matrixFieldId) {
            return null;
        }
        $field = \Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
        return $field?->handle;
    }
}
