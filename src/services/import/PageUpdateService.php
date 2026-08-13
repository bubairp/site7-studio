<?php

namespace site7\studio\services\import;

use craft\elements\Entry;
use site7\studio\records\PackageRecord;
use site7\studio\records\PackageVersionRecord;
use site7\studio\repositories\PageImportSourceRepository;
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
 * frozen class's private methods. Unlike a fresh "Save as Template", this
 * does not attempt the Pattern-run reconstruction TemplateGeneratorService's
 * initial generation does - requires.sections stays a flat, honest list of
 * bare Section handles on update (a pre-existing requires.patterns entry, if
 * any, is left untouched).
 */
class PageUpdateService
{
    /**
     * @return array{addedKeys: string[], removedKeys: string[], changedKeys: string[], unchangedKeys: string[]}
     * @throws \Exception if the package isn't an imported Page, or its source Entry no longer exists.
     */
    public function diff(string $packageHandle): array
    {
        [, , $manifestData, $recaptured] = $this->resolve($packageHandle);

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
     * current content, in place - never touches handle/name/type/version/
     * author/category/tags/description. Preserves the package's DB id and
     * every existing reference.
     *
     * @throws \Exception if the package isn't an imported Page, or its source Entry no longer exists.
     */
    public function updateInPlace(string $packageHandle): PackageRecord
    {
        [$record, $entry, $manifestData, $recaptured, $sourceRecord] = $this->resolve($packageHandle);

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

        $version = new PackageVersionRecord();
        $version->packageId = $record->id;
        $version->version = $record->version;
        $version->releaseDate = date('Y-m-d H:i:s');
        $version->releaseNotes = "Synced from the live Craft page '{$entry->title}'.";
        $version->checksum = $sourceHash;
        $version->save();

        return $record;
    }

    /**
     * @return array{0: PackageRecord, 1: Entry, 2: array, 3: array{entryFields: array, demoContent: array, requires: array, dependencies?: array, excludedFields?: array}, 4: \site7\studio\records\PageImportSourceRecord}
     * @throws \Exception if the package isn't an imported Page, or its source Entry no longer exists.
     */
    private function resolve(string $packageHandle): array
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

        $recaptured = $this->recapture($entry);

        return [$record, $entry, $manifestData, $recaptured, $sourceRecord];
    }

    /**
     * @return array{entryFields: array, demoContent: array, requires: array, dependencies?: array, excludedFields?: array}
     */
    private function recapture(Entry $entry): array
    {
        $matrixHandle = $this->getMatrixFieldHandle();
        $hasSite7Content = false;
        if ($matrixHandle && $entry->getFieldLayout()?->getFieldByHandle($matrixHandle)) {
            $fieldValue = $entry->getFieldValue($matrixHandle);
            $hasSite7Content = $fieldValue && $fieldValue->status(null)->drafts(null)->savedDraftsOnly(false)->count() > 0;
        }

        $hasher = new EntrySourceHasher();

        if (!$hasSite7Content) {
            [, $entryFields, $sharedResourceHandles, $pluginDependencies, $excludedFields] = (new PageImportService())->captureNativeFields($entry, $matrixHandle);
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
            $demoContent[$sectionHandle] = $hasher->extractScalarFieldValues($block);
        }

        return [
            'entryFields' => $hasher->extractScalarFieldValues($entry, [$matrixHandle]),
            'demoContent' => $demoContent,
            'requires' => array_filter(['sections' => array_values(array_unique($sectionHandles))]),
        ];
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

    private function normalize(mixed $value): string
    {
        return json_encode($value);
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
