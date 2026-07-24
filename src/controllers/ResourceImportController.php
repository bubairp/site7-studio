<?php

namespace site7\studio\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use site7\studio\services\import\CraftResourceDiscoveryService;
use site7\studio\services\import\CraftSectionImportService;
use site7\studio\services\import\MatrixEntryTypeImportService;
use site7\studio\services\import\PageImportService;
use site7\studio\services\import\ResourceAnalyzerService;
use site7\studio\services\import\WebsiteImportService;
use site7\studio\Site7Studio;
use yii\web\ForbiddenHttpException;

/**
 * Backs the Craft Resource Import & Package Generator (Phase 15) wizard's
 * Select -> Analyze -> Preview -> Save flow for all four import sources.
 * Gated the same as Package Authoring (Site7Studio::isDevMode()) - import is
 * Package Authoring, just from a live Craft resource instead of a blank
 * canvas. Follows the same JSON listing/action-endpoint pattern as
 * StarterKitGeneratorController/TemplateGeneratorController; no dedicated CP
 * route is registered for any of these actions, same as those controllers.
 */
class ResourceImportController extends Controller
{
    private function requireImportAccess(): void
    {
        if (!Site7Studio::isDevMode()) {
            throw new ForbiddenHttpException('Craft Resource Import is only available in Dev Mode.');
        }
    }

    // --- Matrix Entry Type / Craft Section (-> Section package) ---

    /**
     * Phase 17: the Craft Resource Discovery Engine classifies every Matrix
     * Entry Type in the project (Presentation Section/Feature Component/
     * Shared Resource/Utility Component/Plugin Component/Unknown) instead of
     * listing them flat and undifferentiated. Read-only - does not touch the
     * Analyze/Preview/Save pipeline below.
     */
    public function actionGetMatrixEntryTypes()
    {
        $this->requireAcceptsJson();
        $this->requireImportAccess();

        $discovery = new CraftResourceDiscoveryService();
        $groups = $discovery->discoverEntryTypes();
        $serialize = fn($results) => array_map(fn($r) => $r->toArray(), $results);

        return $this->asJson([
            'success' => true,
            'matrixFields' => $discovery->getMatrixFields(),
            'presentationSections' => $serialize($groups['presentationSections']),
            'featureComponents' => $serialize($groups['featureComponents']),
            'sharedResources' => $serialize($groups['sharedResources']),
            'utilities' => $serialize($groups['utilities']),
            'pluginComponents' => $serialize($groups['pluginComponents']),
            'unknown' => $serialize($groups['unknown']),
        ]);
    }

    /**
     * Phase 17: the Resource Detail view for a single Matrix Entry Type -
     * full dependency/plugin-requirement/shared-resource/estimated-size/
     * potential-issues breakdown, fetched on demand when a resource is
     * selected in the grouped Select step.
     */
    public function actionEntryTypeDetail()
    {
        $this->requireAcceptsJson();
        $this->requireImportAccess();

        $entryTypeId = (int)Craft::$app->getRequest()->getRequiredQueryParam('entryTypeId');

        try {
            $detail = (new CraftResourceDiscoveryService())->getEntryTypeDetail($entryTypeId);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'detail' => $detail->toArray()]);
    }

    public function actionGetCraftSections()
    {
        $this->requireAcceptsJson();
        $this->requireImportAccess();

        $sections = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $sections[] = [
                'id' => $section->id,
                'handle' => $section->handle,
                'name' => $section->name,
                'type' => $section->type,
                'entryTypes' => array_map(fn($et) => [
                    'id' => $et->id,
                    'handle' => $et->handle,
                    'name' => $et->name,
                ], $section->getEntryTypes()),
            ];
        }

        return $this->asJson(['success' => true, 'sections' => $sections]);
    }

    public function actionAnalyzeSection()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireImportAccess();

        $request = Craft::$app->getRequest();
        $sourceKind = (string)$request->getRequiredBodyParam('sourceKind');
        $analyzer = new ResourceAnalyzerService();

        try {
            if ($sourceKind === 'craft-section') {
                $sectionId = (int)$request->getRequiredBodyParam('sectionId');
                $entryTypeIds = array_map('intval', (array)$request->getBodyParam('entryTypeIds', []));
                $analysis = $analyzer->analyzeCraftSection($sectionId, $entryTypeIds);
            } else {
                $entryTypeId = (int)$request->getRequiredBodyParam('entryTypeId');
                $analysis = $analyzer->analyzeMatrixEntryType($entryTypeId);
            }
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'analysis' => $analysis->toArray()]);
    }

    public function actionImportSection()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireImportAccess();

        $request = Craft::$app->getRequest();
        $sourceKind = (string)$request->getRequiredBodyParam('sourceKind');
        $meta = $this->readMeta($request);

        try {
            if ($sourceKind === 'craft-section') {
                $sectionId = (int)$request->getRequiredBodyParam('sectionId');
                $entryTypeIds = array_map('intval', (array)$request->getBodyParam('entryTypeIds', []));
                [$records, $skipped] = (new CraftSectionImportService())->importFromSection($sectionId, $entryTypeIds, $meta);
                $handles = array_map(fn($r) => $r->handle, $records);
            } else {
                $entryTypeId = (int)$request->getRequiredBodyParam('entryTypeId');
                $record = (new MatrixEntryTypeImportService())->importFromEntryType($entryTypeId, $meta);
                $handles = [$record->handle];
                $skipped = [];
            }
        } catch (\Throwable $e) {
            Craft::error('Import Section failed: ' . $e->getMessage(), __METHOD__);
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'handles' => $handles, 'skipped' => $skipped]);
    }

    // --- Pages (-> Template package) ---

    public function actionGetPages()
    {
        $this->requireAcceptsJson();
        $this->requireImportAccess();

        // ->status(null)->all() returns every Entry element project-wide,
        // including nested Matrix block entries (which have no Section of
        // their own) - filtered out here so the list only ever shows real,
        // top-level pages.
        $entries = array_values(array_filter(
            Entry::find()->status(null)->orderBy('title')->all(),
            fn(Entry $e) => $e->getSection() !== null
        ));

        $settings = Site7Studio::getInstance()->getSettings();
        $matrixHandle = null;
        if ($settings->matrixFieldId) {
            $matrixField = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
            $matrixHandle = $matrixField?->handle;
        }

        // Grouped by Section type (Single/Channel/Structure) - same
        // accordion-by-type treatment as actionGetWebsiteResources(), so
        // the two pickers behave consistently instead of one being a flat
        // list and the other grouped.
        $pagesBySectionType = ['single' => [], 'channel' => [], 'structure' => []];
        foreach ($entries as $e) {
            $hasSite7Content = false;
            if ($matrixHandle && $e->getFieldLayout()?->getFieldByHandle($matrixHandle)) {
                $fv = $e->getFieldValue($matrixHandle);
                $hasSite7Content = $fv && $fv->status(null)->drafts(null)->savedDraftsOnly(false)->count() > 0;
            }
            $section = $e->getSection();
            $pagesBySectionType[$section->type][] = [
                'id' => $e->id,
                'title' => $e->title,
                'section' => $section->name,
                'entryType' => $e->getType()->name,
                'hasSite7Content' => $hasSite7Content,
            ];
        }

        return $this->asJson(['success' => true, 'pagesBySectionType' => $pagesBySectionType]);
    }

    public function actionAnalyzePage()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireImportAccess();

        $entryId = (int)Craft::$app->getRequest()->getRequiredBodyParam('entryId');

        try {
            $analysis = (new ResourceAnalyzerService())->analyzeEntry($entryId);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'analysis' => $analysis->toArray()]);
    }

    public function actionImportPage()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireImportAccess();

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getRequiredBodyParam('entryId');
        $meta = $this->readMeta($request);

        $entry = Entry::find()->id($entryId)->status(null)->one();
        if (!$entry instanceof Entry) {
            return $this->asJson(['success' => false, 'error' => 'Page not found.']);
        }

        try {
            $record = (new PageImportService())->importFromEntry($entry, $meta);
        } catch (\Throwable $e) {
            Craft::error('Import Page failed: ' . $e->getMessage(), __METHOD__);
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'handle' => $record->handle]);
    }

    // --- Website (-> Starter Kit package) ---

    public function actionGetWebsiteResources()
    {
        $this->requireAcceptsJson();
        $this->requireImportAccess();

        // Same nested-Matrix-block-entry exclusion as actionGetPages() - a
        // Starter Kit is built from real pages only.
        $entries = array_values(array_filter(
            Entry::find()->status(null)->orderBy('title')->all(),
            fn(Entry $e) => $e->getSection() !== null
        ));

        // Grouped by Section type (Single/Channel/Structure) so the "Import
        // Existing Website" picker can render an accordion instead of one
        // long flat list - the grouping a user browses a real site by.
        $entriesBySectionType = ['single' => [], 'channel' => [], 'structure' => []];
        foreach ($entries as $e) {
            $section = $e->getSection();
            $row = [
                'id' => $e->id,
                'title' => $e->title,
                'section' => $section?->name,
            ];
            $entriesBySectionType[$section->type][] = $row;
        }

        $globalSets = array_map(fn($gs) => [
            'id' => $gs->id,
            'handle' => $gs->handle,
            'name' => $gs->name,
            'likelyNav' => (bool)preg_match('/nav/i', $gs->handle . ' ' . $gs->name),
        ], Craft::$app->getGlobals()->getAllSets());

        $categoryGroups = array_map(fn($g) => ['id' => $g->id, 'handle' => $g->handle, 'name' => $g->name], Craft::$app->getCategories()->getAllGroups());
        $tagGroups = array_map(fn($g) => ['id' => $g->id, 'handle' => $g->handle, 'name' => $g->name], Craft::$app->getTags()->getAllTagGroups());

        return $this->asJson([
            'success' => true,
            'entriesBySectionType' => $entriesBySectionType,
            'globalSets' => $globalSets,
            'categoryGroups' => $categoryGroups,
            'tagGroups' => $tagGroups,
        ]);
    }

    public function actionAnalyzeWebsite()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireImportAccess();

        $request = Craft::$app->getRequest();
        $entryIds = array_map('intval', (array)$request->getBodyParam('entryIds', []));
        $globalSetIds = array_map('intval', (array)$request->getBodyParam('globalSetIds', []));

        try {
            $analysis = (new ResourceAnalyzerService())->analyzeWebsite($entryIds, $globalSetIds);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'analysis' => $analysis->toArray()]);
    }

    public function actionImportWebsite()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireImportAccess();

        $request = Craft::$app->getRequest();
        $entryIds = array_map('intval', (array)$request->getBodyParam('entryIds', []));
        $globalSetIds = array_map('intval', (array)$request->getBodyParam('globalSetIds', []));
        $meta = $this->readMeta($request);

        if (empty($entryIds)) {
            return $this->asJson(['success' => false, 'error' => 'Select at least one page.']);
        }

        try {
            [$record, $skipped, $notes] = (new WebsiteImportService())->importWebsite($entryIds, $globalSetIds, $meta);
        } catch (\Throwable $e) {
            Craft::error('Import Website failed: ' . $e->getMessage(), __METHOD__);
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'handle' => $record->handle, 'skipped' => $skipped, 'notes' => $notes]);
    }

    private function readMeta(\craft\web\Request $request): array
    {
        return [
            'name' => trim((string)$request->getBodyParam('name', '')),
            'description' => (string)$request->getBodyParam('description', ''),
            'version' => (string)$request->getBodyParam('version', '1.0.0'),
            'author' => (string)$request->getBodyParam('author', ''),
            'category' => (string)$request->getBodyParam('category', ''),
            'tags' => (string)$request->getBodyParam('tags', ''),
        ];
    }
}
