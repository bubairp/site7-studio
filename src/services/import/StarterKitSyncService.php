<?php

namespace site7\studio\services\import;

use craft\elements\Entry;
use site7\studio\records\PackageRecord;
use site7\studio\repositories\WebsiteImportSourceRepository;
use site7\studio\Site7Studio;

/**
 * Phase 9.3's "Synchronize Starter Kit" workflow. Deliberately thin: all the
 * actual update-execution logic already exists (SectionUpdateService/
 * PageUpdateService::updateInPlace(), Phases 9.1/9.2) - this class only
 * decides *which* referenced packages have drifted (via
 * StarterKitReferenceResolverService, itself reusing
 * PackageAuthoringService::getSectionImportStatus()/getPageImportStatus())
 * and calls the existing per-type update service for each one. No new
 * diff/apply machinery, per this phase's "reuse... without introducing
 * duplicate logic" requirement.
 */
class StarterKitSyncService
{
    /**
     * @return array{isImported: bool, sourceHash: ?string, importedAt: ?string, updateAvailable: bool}
     */
    public function getStatus(string $starterKitHandle): array
    {
        $default = ['isImported' => false, 'sourceHash' => null, 'importedAt' => null, 'updateAvailable' => false];

        $record = Site7Studio::getInstance()->packageManager->getPackageByHandle($starterKitHandle);
        if (!$record) {
            return $default;
        }

        $sourceRecord = (new WebsiteImportSourceRepository())->findByPackageId($record->id);
        if (!$sourceRecord) {
            return $default;
        }

        $referenceStatus = (new StarterKitReferenceResolverService())->resolve($starterKitHandle);

        return [
            'isImported' => true,
            'sourceHash' => $sourceRecord->sourceHash,
            'importedAt' => $sourceRecord->importedAt,
            'updateAvailable' => $referenceStatus['anyUpdateAvailable'],
        ];
    }

    /**
     * @return array{updated: string[], skipped: string[]}
     * @throws \Exception if the package isn't an imported Starter Kit.
     */
    public function synchronize(string $starterKitHandle): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($starterKitHandle);
        if (!$record || $record->type !== 'starter-kit') {
            throw new \Exception('This package is not a Starter Kit.');
        }

        $sourceRepo = new WebsiteImportSourceRepository();
        $sourceRecord = $sourceRepo->findByPackageId($record->id);
        if (!$sourceRecord) {
            throw new \Exception('This Starter Kit was not produced by Import Existing Website - there is no live source to synchronize from.');
        }

        $referenceStatus = (new StarterKitReferenceResolverService())->resolve($starterKitHandle);

        $updated = [];
        $skipped = [];
        $sectionUpdater = new SectionUpdateService();
        $pageUpdater = new PageUpdateService();

        foreach ($referenceStatus['references'] as $reference) {
            if (!$reference['updateAvailable']) {
                continue;
            }
            try {
                if ($reference['type'] === 'section') {
                    $sectionUpdater->updateInPlace($reference['handle']);
                } elseif ($reference['type'] === 'template') {
                    $pageUpdater->updateInPlace($reference['handle']);
                } else {
                    continue;
                }
                $updated[] = $reference['handle'];
            } catch (\Throwable $e) {
                $skipped[] = $reference['handle'] . ': ' . $e->getMessage();
            }
        }

        // Refresh this Starter Kit's own tracking row - same aggregate-hash
        // formula WebsiteImportService uses at capture time, recomputed from
        // the tracked page selection now that referenced packages are current.
        $entryUids = (array)json_decode($sourceRecord->sourceEntryUids, true);
        $entries = empty($entryUids) ? [] : Entry::find()->uid($entryUids)->status(null)->all();
        $pageHashes = array_map(fn(Entry $e) => (new EntrySourceHasher())->computeHash($e), $entries);
        sort($pageHashes);

        $manifest = $record->getManifest();
        $aggregateHash = hash('sha256', json_encode([
            'pageHashes' => $pageHashes,
            'templates' => (array)($manifest?->requires['templates'] ?? []),
            'sections' => (array)($manifest?->requires['sections'] ?? []),
            'patterns' => (array)($manifest?->requires['patterns'] ?? []),
        ], JSON_UNESCAPED_SLASHES));

        $sourceRepo->record($record->id, $entryUids, $aggregateHash);

        return ['updated' => $updated, 'skipped' => $skipped];
    }
}
