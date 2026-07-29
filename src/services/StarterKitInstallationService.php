<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use site7\studio\Site7Studio;

/**
 * Installs a Starter Kit package ("Install Starter Kit"), recreating its
 * captured pages via the existing Create-from-Template mechanism. Phase 10
 * scope: Pages + Templates only - Navigation/Categories/Assets/SEO are
 * deferred to later increments. Global Set values captured by
 * WebsiteImportService (manifest->globals) are restored on a best-effort
 * basis: a Global Set missing on the target site, or a field no longer on
 * its layout, is skipped and reported rather than failing the install.
 */
class StarterKitInstallationService extends Component
{
    /**
     * @return array{createdEntries: Entry[], skipped: string[], installedTemplates: string[], installedGlobals: string[]}
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

        $installedGlobals = $this->installGlobals($manifest->globals, $skipped);

        return [
            'createdEntries' => $createdEntries,
            'skipped' => $skipped,
            'installedTemplates' => array_values(array_unique($installedTemplates)),
            'installedGlobals' => $installedGlobals,
        ];
    }

    /**
     * Restores captured Global Set field values (manifest->globals: [{globalSetHandle,
     * name, fields: {handle: value}}]) onto the matching Global Set on the target
     * site, if one exists. Missing Global Sets or fields no longer on the target's
     * field layout are appended to $skipped rather than failing the install.
     *
     * @param array $globals
     * @param string[] $skipped
     * @return string[] handles of the Global Sets actually updated
     */
    private function installGlobals(array $globals, array &$skipped): array
    {
        $installed = [];
        $globalsService = Craft::$app->getGlobals();

        foreach ($globals as $global) {
            $handle = $global['globalSetHandle'] ?? null;
            $name = $global['name'] ?? $handle ?? 'Untitled global';
            if (!$handle) {
                continue;
            }

            $globalSet = $globalsService->getSetByHandle($handle);
            if (!$globalSet instanceof GlobalSet) {
                $skipped[] = "{$name}: Global Set '{$handle}' is not installed in this project.";
                continue;
            }

            $fieldLayout = $globalSet->getFieldLayout();
            foreach ($global['fields'] ?? [] as $fieldHandle => $fieldValue) {
                if ($fieldLayout?->getFieldByHandle($fieldHandle)) {
                    $globalSet->setFieldValue($fieldHandle, $fieldValue);
                }
            }

            if (!Craft::$app->getElements()->saveElement($globalSet)) {
                $skipped[] = "{$name}: " . implode(' ', $globalSet->getFirstErrors());
                continue;
            }

            $installed[] = $handle;
        }

        return $installed;
    }
}
