<?php

namespace site7\studio\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use craft\web\UploadedFile;
use site7\studio\Site7Studio;
use site7\studio\services\import\WebsiteTreeService;
use site7\studio\services\StarterKitGeneratorService;
use site7\studio\services\StarterKitInstallationService;

class StarterKitGeneratorController extends Controller
{
    /**
     * Builds the "Pages to Include" picker data for Save/Update Starter Kit, reusing
     * WebsiteTreeService's Website Structure tree (the exact same component the
     * Package Editor's read-only Starter Kit view and "Import Existing Website"
     * already use - see docs/32_STARTER_KIT_SYSTEM.md 14.1) instead of a flat,
     * ungrouped checklist. This replaced an earlier flat-list version that (a) only
     * showed Entries whose Entry Type had the Site7 Matrix field, silently hiding
     * every other real page, and (b), once broadened to show every Entry site-wide,
     * turned out to be unusable on a real project: hundreds of blank-titled/
     * duplicate-titled stress-test Entries with no grouping at all.
     *
     * Categories and Global Sets are stripped from the tree entirely - neither was
     * ever part of a Starter Kit's page list. Singles are included ONLY when they
     * carry their own genuine Site7 Matrix content (e.g. "Home") - see
     * docs/32_STARTER_KIT_SYSTEM.md 14.1.3/14.1.11. A purely structural Single
     * (Header/Footer/General/etc.) is never shown here at all: its field
     * configuration is ALWAYS captured automatically and unconditionally via
     * StarterKitGeneratorService::captureSiteStructure(), regardless of whether it's
     * selected, and its actual rendering (the Twig markup that turns those field
     * values into HTML) lives in the site's own `templates/` codebase - not
     * something a Template package captures or should try to. Showing it here would
     * only ever be redundant (already auto-included) or a no-op (no Site7 content to
     * capture as a page, so selecting it just lands in the visible skip summary) -
     * confirmed by direct user feedback that this was confusing clutter, not useful
     * choice. Entries with no title are filtered out too (never by WebsiteTreeService
     * itself, which stays a faithful, unfiltered mirror of Craft's structure for its
     * other consumers) - a blank title carries no information a user could select by,
     * and this codebase's dev/test database in particular accumulates hundreds of
     * these from repeated stress-test runs.
     */
    public function actionGetEntries()
    {
        $this->requireAcceptsJson();
        if (!Site7Studio::isDevMode()) {
            throw new \yii\web\ForbiddenHttpException('Package Authoring is only available in Dev Mode.');
        }

        $treeService = new WebsiteTreeService();
        $tree = $treeService->buildTree();

        // "Update Starter Kit" (see actionSaveAsStarterKit()'s $handle param) reuses
        // this same tree, pre-checking whatever pages the target Starter Kit's
        // manifest.pages already lists.
        $updateHandle = (string)Craft::$app->getRequest()->getQueryParam('handle', '');
        if ($updateHandle !== '') {
            $existingPackage = Site7Studio::getInstance()->packageManager->getPackageByHandle($updateHandle);
            $existingManifest = $existingPackage?->getManifest();
            if ($existingManifest) {
                $pageUids = $treeService->resolvePageUidsFromManifest($existingManifest->pages);
                $tree = $treeService->markIncluded($tree, $pageUids);
            }
        }

        // A Single is only worth showing here at all if it carries its own genuine
        // Site7 Matrix content (e.g. "Home") - a purely structural Single (Header/
        // Footer/General/etc., with no Site7 blocks of its own) is always
        // auto-included via captureSiteStructure() regardless of selection, so
        // showing it as a selectable "page" here is either redundant or a no-op. See
        // this method's docblock and docs/32_STARTER_KIT_SYSTEM.md 14.1.11.
        //
        // Exception 1: a Single already marked `included` (Update mode - it's in the
        // target Starter Kit's current manifest.pages, per markIncluded() above)
        // stays visible even without live Site7 content right now - otherwise it
        // would silently vanish from the checklist and get dropped from the
        // manifest on the next save, even though the user never unchecked it.
        // Exception 2: a page-like Single (StarterKitGeneratorService::
        // PAGE_LIKE_SINGLE_SECTIONS - "Home"/"Contact") always stays visible too,
        // Site7 content or not - captureSiteStructure() no longer captures these at
        // all (per explicit decision, docs/32_STARTER_KIT_SYSTEM.md 14.1.12), so the
        // picker is their ONLY capture path; hiding them here would mean their
        // content is never captured anywhere.
        unset($tree['categories'], $tree['globalSets']);
        $tree['singles'] = array_values(array_filter(
            $this->filterBlankTitlesRecursive($tree['singles'] ?? []),
            fn(array $entry) => !empty($entry['hasSite7Content'])
                || !empty($entry['included'])
                || in_array($entry['sectionHandle'] ?? null, StarterKitGeneratorService::PAGE_LIKE_SINGLE_SECTIONS, true)
        ));
        $tree['channels'] = array_map([$this, 'filterBlankTitledEntries'], $tree['channels'] ?? []);
        $tree['structures'] = array_map([$this, 'filterBlankTitledEntries'], $tree['structures'] ?? []);

        return $this->asJson(['success' => true, 'tree' => $tree]);
    }

    private function filterBlankTitledEntries(array $section): array
    {
        $section['entries'] = $this->filterBlankTitlesRecursive($section['entries'] ?? []);
        return $section;
    }

    private function filterBlankTitlesRecursive(array $entries): array
    {
        $filtered = [];
        foreach ($entries as $entry) {
            if (trim((string)($entry['title'] ?? '')) === '') {
                continue;
            }
            if (!empty($entry['children'])) {
                $entry['children'] = $this->filterBlankTitlesRecursive($entry['children']);
            }
            $filtered[] = $entry;
        }
        return array_values($filtered);
    }

    /**
     * Generates a new Starter Kit package from the selected Entries ("Save Current
     * Site as Starter Kit"), OR - when the optional `handle` param is given - updates
     * an existing Starter Kit in place ("Update Starter Kit"): overwrites the
     * manifest/README/preview at that same package handle rather than minting a new
     * one, re-running the exact same page-capture and siteStructure-capture logic.
     * The create-new (no `handle`) behavior is unchanged.
     */
    public function actionSaveAsStarterKit()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        if (!Site7Studio::isDevMode()) {
            throw new \yii\web\ForbiddenHttpException('Package Authoring is only available in Dev Mode.');
        }

        $request = Craft::$app->getRequest();
        $entryIds = (array)$request->getRequiredBodyParam('entryIds');
        $name = trim((string)$request->getRequiredBodyParam('name'));
        $existingHandle = trim((string)$request->getBodyParam('handle', ''));

        if ($name === '') {
            return $this->asJson(['success' => false, 'error' => 'A Starter Kit name is required.']);
        }
        if (empty($entryIds)) {
            return $this->asJson(['success' => false, 'error' => 'Select at least one page.']);
        }

        $entries = Entry::find()->id($entryIds)->status(null)->all();
        if (empty($entries)) {
            return $this->asJson(['success' => false, 'error' => 'No valid pages were selected.']);
        }

        $meta = [
            'name' => $name,
            'description' => (string)$request->getBodyParam('description', ''),
            'version' => (string)$request->getBodyParam('version', '1.0.0'),
            'author' => (string)$request->getBodyParam('author', ''),
            'category' => (string)$request->getBodyParam('category', ''),
            'tags' => (string)$request->getBodyParam('tags', ''),
            'previewImage' => UploadedFile::getInstanceByName('previewImage'),
        ];

        try {
            [$record, $skipped] = (new StarterKitGeneratorService())->generateFromEntries($entries, $meta, $existingHandle !== '' ? $existingHandle : null);
        } catch (\Throwable $e) {
            Craft::error('Save as Starter Kit failed: ' . $e->getMessage(), __METHOD__);
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'handle' => $record->handle, 'skipped' => $skipped]);
    }

    /**
     * Installs a Starter Kit's captured pages into the current project ("Install
     * Starter Kit").
     */
    public function actionInstall()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');

        try {
            $summary = (new StarterKitInstallationService())->installStarterKit($handle);
        } catch (\Throwable $e) {
            Craft::error('Install Starter Kit failed: ' . $e->getMessage(), __METHOD__);
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson([
            'success' => true,
            'createdCount' => count($summary['createdEntries']),
            'skipped' => $summary['skipped'],
            'installedTemplates' => $summary['installedTemplates'],
            'installedGlobals' => $summary['installedGlobals'],
            'installedSiteStructure' => $summary['installedSiteStructure'],
        ]);
    }
}
