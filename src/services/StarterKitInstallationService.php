<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\models\Section;
use site7\studio\services\support\AssetCaptureHelper;
use site7\studio\Site7Studio;

/**
 * Installs a Starter Kit package ("Install Starter Kit"), recreating its
 * captured pages via the existing Create-from-Template mechanism. Phase 10
 * scope: Pages + Templates only - Navigation/Categories/Assets/SEO are
 * deferred to later increments. Global Set values captured by
 * WebsiteImportService (manifest->globals) are restored on a best-effort
 * basis: a Global Set missing on the target site, or a field no longer on
 * its layout, is skipped and reported rather than failing the install.
 *
 * A captured page whose own Section is a Single (e.g. "Home") is installed
 * differently from every other page: since Craft never allows a second Entry
 * in a Single section, its captured Template content is applied to that
 * Single's one existing Entry in place (TemplateInsertionService::
 * updateEntryFromTemplate()), never via createEntryFromTemplate()'s "brand
 * new Entry" path used for every Channel/Structure page.
 *
 * manifest->siteStructure (Single-section Header/Footer/General/Theme-style
 * content, see PackageManifest::$siteStructure) is a SEPARATE mechanism from
 * manifest->globals above - installSiteStructure() below restores it onto the
 * target's existing Single-section Entries, not onto any GlobalSet. Do not
 * conflate the two when changing either.
 */
class StarterKitInstallationService extends Component
{
    /**
     * @return array{createdEntries: Entry[], skipped: string[], installedTemplates: string[], installedGlobals: string[], installedSiteStructure: string[]}
     * @throws \Exception if the Starter Kit package or its manifest can't be resolved.
     */
    public function installStarterKit(string $handle): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $package = $packageManager->getPackageByHandle($handle);
        if (!$package) {
            // On a fresh site the kit is on disk but not registered yet - the
            // Install Wizard's catalog reads packages/ directly. Same fallback
            // as the Template lookup below.
            $packageManager->discoverPackages();
            $package = $packageManager->getPackageByHandle($handle);
        }
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
            $sectionHandle = $page['sectionHandle'] ?? null;
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

            $targetSection = $sectionHandle ? $entriesService->getSectionByHandle($sectionHandle) : null;

            if ($targetSection && $targetSection->type === Section::TYPE_SINGLE) {
                // Craft never allows a second Entry in a Single section - this page
                // was originally captured from a Single (e.g. "Home", which can carry
                // its own genuine Site7 Matrix content distinct from the native-field
                // "site structure" installSiteStructure() below always restores) so
                // its captured content is applied to that Single's one existing Entry
                // in place, never via createEntryFromTemplate()'s "brand new Entry"
                // path. See docs/32_STARTER_KIT_SYSTEM.md 14.1.3.
                $existingEntry = Entry::find()->sectionId($targetSection->id)->status(null)->one();
                if (!$existingEntry) {
                    $skipped[] = "{$title}: Section '{$sectionHandle}' has no Entry on this site to update.";
                    continue;
                }
                try {
                    $createdEntries[] = $insertionService->updateEntryFromTemplate($templateHandle, $existingEntry);
                } catch (\Throwable $e) {
                    $skipped[] = "{$title}: " . $e->getMessage();
                }
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
        $installedSiteStructure = $this->installSiteStructure($manifest->siteStructure, $skipped, $packageManager->getPackagePath($handle));

        return [
            'createdEntries' => $createdEntries,
            'skipped' => $skipped,
            'installedTemplates' => array_values(array_unique($installedTemplates)),
            'installedGlobals' => $installedGlobals,
            'installedSiteStructure' => $installedSiteStructure,
        ];
    }

    /**
     * Restores captured Single-section "site structure" content (manifest->
     * siteStructure: [{sectionHandle, entryTypeHandle, title, fields: {handle:
     * value}}]) onto the matching Single section's own, already-existing Entry on
     * the target site - Craft auto-creates that one Entry when the Section itself is
     * created, so this never creates a new Entry itself. A Single section missing on
     * the target (e.g. its owning Section package isn't installed here), or a field
     * no longer on its current layout, is skipped and reported rather than failing
     * the install - same tolerance as installGlobals() above.
     *
     * @param array $siteStructure
     * @param string[] $skipped
     * @param string|null $packagePath The Starter Kit's own package directory - where
     *   captureSiteStructure() bundled any captured Assets field's file(s)
     *   (preview/assets/), needed here to restore them via AssetCaptureHelper the
     *   same way TemplateInsertionService::createEntryFromTemplate() already does
     *   for a Template's entryFields. Without this, an Assets field's captured
     *   descriptor array would be passed straight to setFieldValue() and fail
     *   Craft's Assets normalizer.
     * @return string[] sectionHandles actually updated
     */
    private function installSiteStructure(array $siteStructure, array &$skipped, ?string $packagePath): array
    {
        $installed = [];
        $entriesService = Craft::$app->getEntries();

        foreach ($siteStructure as $item) {
            $sectionHandle = $item['sectionHandle'] ?? null;
            $title = $item['title'] ?? $sectionHandle ?? 'Untitled section';
            if (!$sectionHandle) {
                continue;
            }

            $section = $entriesService->getSectionByHandle($sectionHandle);
            if (!$section) {
                $skipped[] = "{$title}: Section '{$sectionHandle}' is not installed in this project.";
                continue;
            }

            $entry = Entry::find()->sectionId($section->id)->status(null)->one();
            if (!$entry) {
                $skipped[] = "{$title}: Section '{$sectionHandle}' has no Entry on this site to update.";
                continue;
            }

            $fieldLayout = $entry->getFieldLayout();
            foreach ($item['fields'] ?? [] as $fieldHandle => $fieldValue) {
                if (!$fieldLayout?->getFieldByHandle($fieldHandle)) {
                    continue;
                }
                if (AssetCaptureHelper::isAssetDescriptor($fieldValue)) {
                    if ($packagePath) {
                        $assetIds = AssetCaptureHelper::restoreAssetField($fieldValue, $packagePath);
                        if (!empty($assetIds)) {
                            $entry->setFieldValue($fieldHandle, $assetIds);
                        }
                    }
                    continue;
                }
                $entry->setFieldValue($fieldHandle, $fieldValue);
            }

            if (!Craft::$app->getElements()->saveElement($entry)) {
                $skipped[] = "{$title}: " . implode(' ', $entry->getFirstErrors());
                continue;
            }

            $installed[] = $sectionHandle;
        }

        return $installed;
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
