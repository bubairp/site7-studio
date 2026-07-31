<?php

namespace site7\studio\services\import;

use craft\base\Component;
use craft\helpers\FileHelper;
use craft\models\EntryType;
use site7\studio\records\PackageRecord;
use site7\studio\records\PackageVersionRecord;
use site7\studio\records\SectionImportSourceRecord;
use site7\studio\repositories\SectionImportSourceRepository;
use site7\studio\services\CraftResourceRegistry;
use site7\studio\Site7Studio;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 9.1's Update Package workflow: an imported Section package is a
 * read-only mirror of a live Craft Entry Type (see PackageAuthoringService::
 * isLockedImportedSection()) - re-importing it is never allowed, so this is
 * the only way its content can change once imported. Reuses
 * MatrixEntryTypeImportService::detectFields()/writeFieldsYaml()/
 * writeMatrixYaml() so an updated package's fields.yaml/matrix.yaml come out
 * identically shaped to a fresh import - never a second, drifting
 * implementation of that logic.
 */
class SectionUpdateService extends Component
{
    /**
     * @return array{added: array, removed: array, changed: array, unchanged: array}
     * @throws \Exception if the package isn't an imported Section, or its source Entry Type no longer exists.
     */
    public function diff(string $packageHandle): array
    {
        [, $entryType, $importableFields] = $this->resolve($packageHandle);

        $existingFieldsByHandle = $this->readExistingFields($packageHandle);
        $liveFieldsByHandle = [];
        foreach ($importableFields as $field) {
            $liveFieldsByHandle[$field['handle']] = $field;
        }

        $added = [];
        $removed = [];
        $changed = [];
        $unchanged = [];

        foreach ($liveFieldsByHandle as $handle => $field) {
            if (!isset($existingFieldsByHandle[$handle])) {
                $added[] = $field;
                continue;
            }
            $existing = $existingFieldsByHandle[$handle];
            if ($existing['type'] !== $field['type'] || $existing['instructions'] !== $field['instructions']) {
                $changed[] = ['handle' => $handle, 'from' => $existing, 'to' => $field];
            } else {
                $unchanged[] = $field;
            }
        }

        foreach ($existingFieldsByHandle as $handle => $existing) {
            if (!isset($liveFieldsByHandle[$handle])) {
                $removed[] = $existing;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed, 'unchanged' => $unchanged];
    }

    /**
     * Rewrites fields.yaml/matrix.yaml/preview-data.yaml from the live Entry
     * Type's current structure, in place - never touches handle/name/type,
     * and never touches template.twig (same "don't clobber hand-authored
     * markup" rule PackageAuthoringService::saveSectionFields() already
     * follows). Preserves the package's DB id and every existing reference.
     *
     * @throws \Exception if the package isn't an imported Section, or its source Entry Type no longer exists.
     */
    public function updateInPlace(string $packageHandle): PackageRecord
    {
        [$record, $entryType, $importableFields, $sharedResourceHandles, $pluginDependencies, $excludedFields, $sourceRecord] = $this->resolve($packageHandle);

        $packageManager = Site7Studio::getInstance()->packageManager;
        $packagePath = $packageManager->getPackagePath($packageHandle);
        if (!$packagePath) {
            throw new \Exception('Package not found on disk.');
        }

        $importer = new MatrixEntryTypeImportService();
        $importer->writeFieldsYaml($packagePath, $record->name, $importableFields);
        $importer->writeMatrixYaml($packagePath, $record->name, $entryType, $importableFields);

        // Merge rather than blank out - existing hand-entered demo values
        // survive for every field that's still present after the sync.
        $existingDemoValues = [];
        $previewDataPath = $packagePath . '/preview/preview-data.yaml';
        if (file_exists($previewDataPath)) {
            $existingDemoValues = (array)(Yaml::parseFile($previewDataPath)['block'] ?? []);
        }
        FileHelper::createDirectory($packagePath . '/preview');
        file_put_contents($previewDataPath, Yaml::dump([
            'block' => array_combine(
                array_map(fn($f) => $f['handle'], $importableFields),
                array_map(fn($f) => $existingDemoValues[$f['handle']] ?? '', $importableFields),
            ),
        ], 4));

        $sourceHash = (new EntryTypeSourceHasher())->computeHash($entryType);

        $manifestPath = $packagePath . '/manifest.json';
        $manifestData = json_decode(file_get_contents($manifestPath), true) ?: [];
        $manifestData['dependencies'] = [
            'sharedResources' => array_values(array_unique($sharedResourceHandles)),
            'pluginDependencies' => $pluginDependencies,
        ];
        $manifestData['excludedFields'] = $excludedFields;
        $manifestData['importedFrom']['sourceHash'] = $sourceHash;
        $manifestData['importedFrom']['importedAt'] = date('c');
        file_put_contents($manifestPath, json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Idempotent - the source Entry Type already exists, so this only
        // syncs any new/changed fields into its live field layout, exactly
        // as MatrixEntryTypeImportService's own docblock describes.
        $packageManager->discoverPackages();
        $packageManager->installPackage($packageHandle);

        (new SectionImportSourceRepository())->record($record->id, $sourceRecord->sourceUid, $sourceRecord->sourceType, $entryType->handle, $sourceHash);

        $version = new PackageVersionRecord();
        $version->packageId = $record->id;
        $version->version = $record->version;
        $version->releaseDate = date('Y-m-d H:i:s');
        $version->releaseNotes = "Synced from the live Craft Entry Type '{$entryType->handle}'.";
        $version->checksum = $sourceHash;
        $version->save();

        return $record;
    }

    /**
     * @return array{0: PackageRecord, 1: EntryType, 2: array, 3: string[], 4: array, 5: array, 6: SectionImportSourceRecord}
     * @throws \Exception if the package isn't an imported Section, or its source Entry Type no longer exists.
     */
    private function resolve(string $packageHandle): array
    {
        $record = Site7Studio::getInstance()->packageManager->getPackageByHandle($packageHandle);
        if (!$record || $record->type !== 'section') {
            throw new \Exception('This package is not a Section.');
        }

        $sourceRecord = (new SectionImportSourceRepository())->findByPackageId($record->id);
        if (!$sourceRecord) {
            throw new \Exception('This Section was not produced by Import Existing Section - there is no live source to update from.');
        }

        $entryType = (new CraftResourceRegistry())->findByUid(CraftResourceRegistry::KIND_ENTRY_TYPE, $sourceRecord->sourceUid)?->resource;
        if (!$entryType instanceof EntryType) {
            throw new \Exception("The source Entry Type '{$sourceRecord->sourceHandle}' no longer exists in this Craft project.");
        }

        [, $importableFields, $sharedResourceHandles, $pluginDependencies, $excludedFields] = (new MatrixEntryTypeImportService())->detectFields($entryType);

        return [$record, $entryType, $importableFields, $sharedResourceHandles, $pluginDependencies, $excludedFields, $sourceRecord];
    }

    /**
     * @return array<string, array{handle: string, name: string, type: string, instructions: string}>
     */
    private function readExistingFields(string $packageHandle): array
    {
        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($packageHandle);
        $fieldsYamlPath = $packagePath ? $packagePath . '/fields.yaml' : null;
        if (!$fieldsYamlPath || !file_exists($fieldsYamlPath)) {
            return [];
        }

        $fields = [];
        $data = Yaml::parseFile($fieldsYamlPath);
        foreach ($data['fields'] ?? [] as $def) {
            if (empty($def['handle'])) {
                continue;
            }
            $fields[$def['handle']] = [
                'handle' => $def['handle'],
                'name' => $def['name'] ?? $def['handle'],
                'type' => $def['type'] ?? 'PlainText',
                'instructions' => $def['instructions'] ?? '',
            ];
        }
        return $fields;
    }
}
