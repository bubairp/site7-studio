<?php

namespace site7\studio\services\import;

use site7\studio\services\PackageAuthoringService;
use site7\studio\Site7Studio;

/**
 * Phase 9.3's "Starter Kit Relationships" - builds the unified References
 * list (which Template/Section packages a Starter Kit depends on, and each
 * one's current imported/update-available status) from a Starter Kit's own
 * manifest. Reuses PackageAuthoringService::getSectionImportStatus()/
 * getPageImportStatus() - the exact status logic Phases 9.1/9.2 already
 * built - rather than introducing a second hashing/tracking mechanism.
 */
class StarterKitReferenceResolverService
{
    /**
     * @return array{references: array<int, array{type: string, handle: string, name: string, isImported: bool, updateAvailable: bool}>, anyUpdateAvailable: bool}
     */
    public function resolve(string $starterKitHandle): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $authoringService = new PackageAuthoringService();

        $record = $packageManager->getPackageByHandle($starterKitHandle);
        $manifest = $record?->getManifest();
        if (!$manifest) {
            return ['references' => [], 'anyUpdateAvailable' => false];
        }

        $references = [];
        $anyUpdateAvailable = false;

        foreach ((array)($manifest->requires['templates'] ?? []) as $handle) {
            $pkg = $packageManager->getPackageByHandle($handle);
            $status = $authoringService->getPageImportStatus($handle);
            $anyUpdateAvailable = $anyUpdateAvailable || $status['updateAvailable'];
            $references[] = [
                'type' => 'template',
                'handle' => $handle,
                'name' => $pkg->name ?? $handle,
                'isImported' => $status['isImported'],
                'updateAvailable' => $status['updateAvailable'],
            ];
        }

        foreach ((array)($manifest->requires['sections'] ?? []) as $handle) {
            $pkg = $packageManager->getPackageByHandle($handle);
            $status = $authoringService->getSectionImportStatus($handle);
            $anyUpdateAvailable = $anyUpdateAvailable || $status['updateAvailable'];
            $references[] = [
                'type' => 'section',
                'handle' => $handle,
                'name' => $pkg->name ?? $handle,
                'isImported' => $status['isImported'],
                'updateAvailable' => $status['updateAvailable'],
            ];
        }

        return ['references' => $references, 'anyUpdateAvailable' => $anyUpdateAvailable];
    }
}
