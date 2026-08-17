<?php

namespace site7\studio\services\import;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\models\EntryType;
use site7\studio\records\PackageRecord;
use site7\studio\records\SectionImportSourceRecord;
use site7\studio\repositories\SectionImportSourceRepository;
use site7\studio\services\CraftResourceRegistry;
use site7\studio\services\support\PackageArchiveHelper;
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
     * @return array{added: array, removed: array, changed: array, unchanged: array,
     *   twig: array{changed: bool, liveChecksum: ?string, packageChecksum: ?string},
     *   ownedFiles: array<int, array{targetPath: string, changed: bool, liveChecksum: ?string, packageChecksum: ?string}>}
     * @throws \Exception if the package isn't an imported Section, or its source Entry Type no longer exists.
     */
    public function diff(string $packageHandle): array
    {
        [$record, $entryType, $importableFields] = $this->resolve($packageHandle);

        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($packageHandle);
        $existingFieldsByHandle = $this->readExistingFields($packageHandle);
        $manifest = $record->getManifest();

        return array_merge(
            $this->compareFields($existingFieldsByHandle, $importableFields),
            [
                'twig' => $packagePath ? $this->diffTwig($packagePath, $entryType->handle) : ['changed' => false, 'liveChecksum' => null, 'packageChecksum' => null],
                'ownedFiles' => ($packagePath && $manifest) ? $this->diffOwnedFiles($packagePath, $manifest->ownedFiles) : [],
            ],
        );
    }

    /**
     * @param array<string, array{handle: string, name: string, type: string, instructions: string}> $existingFieldsByHandle
     * @param array $liveFields the package type's currently-capturable fields, as detectFields() returns them
     * @return array{added: array, removed: array, changed: array, unchanged: array}
     */
    private function compareFields(array $existingFieldsByHandle, array $liveFields): array
    {
        $liveFieldsByHandle = [];
        foreach ($liveFields as $field) {
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
     * Compares the live templates/_blocks/{sourceHandle}.twig (the real
     * production runtime - see PHASE-6 doc's "no site7-components sandbox"
     * rule) against this package's own stored template.twig, using Step 3's
     * PackageArchiveHelper::computeFileChecksum() convention rather than a
     * second hashing routine. A missing live file is never treated as a
     * change to sync - there is nothing to sync from in that case, and
     * silently blanking an existing template.twig because the live file
     * vanished is a conflict-handling concern (later step), not this one's.
     *
     * @return array{changed: bool, liveChecksum: ?string, packageChecksum: ?string}
     */
    private function diffTwig(string $packagePath, string $sourceHandle): array
    {
        $liveTemplatePath = rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/') . '/_blocks/' . $sourceHandle . '.twig';
        $liveChecksum = PackageArchiveHelper::computeFileChecksum($liveTemplatePath);
        $packageChecksum = PackageArchiveHelper::computeFileChecksum($packagePath . '/template.twig');

        return [
            'changed' => $liveChecksum !== null && $liveChecksum !== $packageChecksum,
            'liveChecksum' => $liveChecksum,
            'packageChecksum' => $packageChecksum,
        ];
    }

    /**
     * The owned-files half of Sync From Source's detection (Step 8.2) -
     * compares each of the package's currently-declared ownedFiles (Step
     * 8.1) between its live site copy and its stored package copy, using
     * the same PackageArchiveHelper::computeFileChecksum() convention
     * diffTwig() already uses. Never adds/removes an owned-file
     * declaration - only reports whether an already-selected file's
     * content changed.
     *
     * @param array<int, array{sourcePath: string, targetPath: string, type: string}> $ownedFiles
     * @return array<int, array{targetPath: string, changed: bool, liveChecksum: ?string, packageChecksum: ?string}>
     */
    private function diffOwnedFiles(string $packagePath, array $ownedFiles): array
    {
        $root = dirname(rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/'));
        $results = [];

        foreach ($ownedFiles as $owned) {
            $sourcePath = (string)($owned['sourcePath'] ?? '');
            $targetPath = (string)($owned['targetPath'] ?? '');
            if ($sourcePath === '' || $targetPath === '') {
                continue;
            }

            $liveChecksum = PackageArchiveHelper::computeFileChecksum($root . '/' . $targetPath);
            $packageChecksum = PackageArchiveHelper::computeFileChecksum($packagePath . '/' . $sourcePath);

            $results[] = [
                'targetPath' => $targetPath,
                'changed' => $liveChecksum !== null && $liveChecksum !== $packageChecksum,
                'liveChecksum' => $liveChecksum,
                'packageChecksum' => $packageChecksum,
            ];
        }

        return $results;
    }

    /**
     * Sync From Source (Phase 9.1, extended for version integrity): re-reads
     * the live Entry Type's field layout AND the live
     * templates/_blocks/{sourceHandle}.twig content, and does nothing at all
     * - no file writes, no re-install, no version - unless at least one of
     * them actually differs from what this package currently has. When
     * something did change, package files are updated in place (never
     * handle/name/type - same "don't clobber hand-authored markup" rule
     * PackageAuthoringService::saveSectionFields() follows) and exactly one
     * new immutable version is created through VersionManagerService::
     * createVersion() (Step 4) - which already produces a real .s7pkg
     * archive and checksum via the existing export/recordVersion path, so
     * this method never builds a second one.
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

        $existingFieldsByHandle = $this->readExistingFields($packageHandle);
        $fieldsDiff = $this->compareFields($existingFieldsByHandle, $importableFields);
        $twigDiff = $this->diffTwig($packagePath, $entryType->handle);
        $manifest = $record->getManifest();
        $ownedFilesDiff = $manifest ? $this->diffOwnedFiles($packagePath, $manifest->ownedFiles) : [];
        $changedOwnedFiles = array_values(array_filter($ownedFilesDiff, fn(array $f) => $f['changed']));

        $fieldsChanged = !empty($fieldsDiff['added']) || !empty($fieldsDiff['removed']) || !empty($fieldsDiff['changed']);
        if (!$fieldsChanged && !$twigDiff['changed'] && empty($changedOwnedFiles)) {
            // Nothing meaningful changed since the last sync - not even the
            // source-hash bookkeeping is touched, so calling this repeatedly
            // with no intervening source change is a true no-op.
            return $record;
        }

        $importer = new MatrixEntryTypeImportService();
        $importer->writeFieldsYaml($packagePath, $record->name, $importableFields);
        $importer->writeMatrixYaml($packagePath, $record->name, $entryType, $importableFields);
        if ($twigDiff['changed']) {
            $importer->copyTemplateTwigFromLiveSource($packagePath, $entryType->handle);
        }
        if (!empty($changedOwnedFiles) && $manifest) {
            // Re-copies only the files diffOwnedFiles() actually flagged as
            // changed (syncOwnedFilesFromLiveSource() re-derives the same
            // changed/unchanged verdict itself before writing, so an
            // unchanged owned file is never rewritten even though it's
            // passed through here for simplicity).
            $importer->syncOwnedFilesFromLiveSource($packagePath, $manifest->ownedFiles);
        }

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

        // A field addition/removal is a shape change (minor); a field-type/
        // instructions change or a Twig-only change is a patch - the same
        // "don't guess at a major/breaking bump automatically" stance
        // VersionManagerService::bumpVersion() already implies by only
        // offering patch/minor/major, never inferring one from content.
        $bumpType = (!empty($fieldsDiff['added']) || !empty($fieldsDiff['removed'])) ? 'minor' : 'patch';
        Site7Studio::getInstance()->versionManager->createVersion(
            $packageHandle,
            $bumpType,
            $this->summarizeSyncChanges($fieldsDiff, $twigDiff, $changedOwnedFiles, $entryType->handle),
        );

        $record->refresh();

        return $record;
    }

    private function summarizeSyncChanges(array $fieldsDiff, array $twigDiff, array $changedOwnedFiles, string $sourceHandle): string
    {
        $parts = [];
        if (!empty($fieldsDiff['added'])) {
            $parts[] = count($fieldsDiff['added']) . ' field(s) added';
        }
        if (!empty($fieldsDiff['removed'])) {
            $parts[] = count($fieldsDiff['removed']) . ' field(s) removed';
        }
        if (!empty($fieldsDiff['changed'])) {
            $parts[] = count($fieldsDiff['changed']) . ' field(s) changed';
        }
        if ($twigDiff['changed']) {
            $parts[] = 'template.twig updated';
        }
        if (!empty($changedOwnedFiles)) {
            $parts[] = count($changedOwnedFiles) . ' owned file(s) updated';
        }

        $summary = $parts === [] ? 'no detected changes' : implode(', ', $parts);
        return "Synced from the live Craft Entry Type '{$sourceHandle}': {$summary}.";
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
