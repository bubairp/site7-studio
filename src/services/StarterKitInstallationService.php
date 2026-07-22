<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use site7\studio\Site7Studio;

/**
 * Installs a Starter Kit package ("Install Starter Kit"), recreating its
 * captured pages via the existing Create-from-Template mechanism. Phase 10
 * scope: Pages + Templates only - Navigation/Globals/Categories/Assets/SEO
 * are deferred to later increments, so the installation summary only ever
 * reports on pages/templates.
 */
class StarterKitInstallationService extends Component
{
    /**
     * @return array{createdEntries: Entry[], skipped: string[], installedTemplates: string[]}
     * @throws \Exception if the Starter Kit package or its manifest can't be resolved.
     */
    public function installStarterKit(string $handle): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $package = $packageManager->getPackageByHandle($handle);
        if (!$package || $package->type !== 'starter-kit') {
            throw new \Exception('Starter Kit package not found.');
        }

        $manifest = $package->getManifest();
        if (!$manifest || empty($manifest->pages)) {
            throw new \Exception('This Starter Kit has no pages to install.');
        }

        // Dependency validation + install: every referenced Template must exist and
        // be enabled before any page can reference it. Missing ones are reported as
        // skipped dependencies rather than failing the whole install, so the rest of
        // the site can still be recreated.
        $installedTemplates = [];
        $missingTemplates = [];
        foreach ($manifest->requires['templates'] ?? [] as $templateHandle) {
            $templateRecord = $packageManager->getPackageByHandle($templateHandle);
            if (!$templateRecord) {
                $packageManager->discoverPackages();
                $templateRecord = $packageManager->getPackageByHandle($templateHandle);
            }
            if (!$templateRecord) {
                $missingTemplates[$templateHandle] = true;
                continue;
            }
            if ($templateRecord->status !== 'enabled') {
                if ($templateRecord->status === 'available') {
                    $packageManager->installPackage($templateHandle);
                }
                $packageManager->enablePackage($templateHandle);
            }
            $installedTemplates[] = $templateHandle;
        }

        $insertionService = new TemplateInsertionService();
        $entriesService = Craft::$app->getEntries();

        $createdEntries = [];
        $skipped = [];

        foreach ($manifest->pages as $page) {
            $templateHandle = $page['templateHandle'] ?? null;
            $entryTypeHandle = $page['entryTypeHandle'] ?? null;
            $title = $page['title'] ?? 'Untitled';

            if ($templateHandle && isset($missingTemplates[$templateHandle])) {
                $skipped[] = "{$title}: required Template '{$templateHandle}' is missing.";
                continue;
            }

            $entryType = $entryTypeHandle ? $entriesService->getEntryTypeByHandle($entryTypeHandle) : null;
            if (!$entryType) {
                $skipped[] = "{$title}: Entry Type '{$entryTypeHandle}' is not installed in this project.";
                continue;
            }

            try {
                $createdEntries[] = $insertionService->createEntryFromTemplate(
                    $templateHandle,
                    $entryType->id,
                    $title,
                    $page['slug'] ?? null
                );
            } catch (\Throwable $e) {
                $skipped[] = "{$title}: " . $e->getMessage();
            }
        }

        return [
            'createdEntries' => $createdEntries,
            'skipped' => $skipped,
            'installedTemplates' => array_values(array_unique($installedTemplates)),
        ];
    }
}
