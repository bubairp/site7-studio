<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;

/**
 * Backs the Library's "Shared Resources" tab (Phase 16). Shared Resources
 * aren't PackageRecords - they're live Craft resources (fields, entry types,
 * volumes, category/tag groups, global sets) intentionally reused across
 * many packages - so this is a dedicated controller/template pair rather
 * than a 5th `type=` branch on LibraryController::actionIndex(), which is
 * built entirely around PackageRecord-shaped data.
 */
class SharedResourceController extends Controller
{
    public function actionIndex()
    {
        $registry = Site7Studio::getInstance()->sharedResourceRegistry;
        $rows = [];
        foreach ($registry->getAll() as $record) {
            $rows[] = [
                'record' => $record,
                'usageCount' => $registry->getUsageCount($record->handle),
                'dependentPackages' => $registry->getDependentPackages($record->handle),
            ];
        }

        return $this->renderTemplate('site7-studio/library/shared-resources', [
            'title' => 'Shared Resources',
            'rows' => $rows,
        ]);
    }

    public function actionPreview(string $handle)
    {
        $registry = Site7Studio::getInstance()->sharedResourceRegistry;
        $record = $registry->getByHandle($handle);
        if (!$record) {
            throw new \yii\web\NotFoundHttpException('Shared Resource not found.');
        }

        $describedFields = [];
        if ($record->type === 'entryType') {
            $entryType = Craft::$app->getEntries()->getEntryTypeByHandle($handle);
            $layout = $entryType?->getFieldLayout();
            if ($layout) {
                $describedFields = Site7Studio::getInstance()->craftResourceGenerator->describeFieldLayout($layout);
            }
        }

        return $this->renderTemplate('site7-studio/library/shared-resource', [
            'title' => $record->name,
            'record' => $record,
            'describedFields' => $describedFields,
            'usageCount' => $registry->getUsageCount($handle),
            'dependentPackages' => $registry->getDependentPackages($handle),
            'dependentSharedResources' => $registry->getDependentSharedResources($handle),
        ]);
    }

    /**
     * Manually registers an already-existing live Craft field into the
     * registry by handle - the "Import" action for a Shared Resource that
     * DependencyResolverService reported as missing.
     */
    public function actionImport()
    {
        $this->requirePostRequest();

        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');
        $field = Craft::$app->getFields()->getFieldByHandle($handle);

        if (!$field) {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', "No live Craft field with handle '{$handle}' was found."));
            return $this->redirectToPostedUrl();
        }

        $type = $field instanceof \craft\fields\Matrix ? 'matrix' : 'field';
        Site7Studio::getInstance()->sharedResourceRegistry->registerIfMissing([
            'handle' => $field->handle,
            'name' => $field->name,
            'type' => $type,
            'craftUid' => $field->uid,
            'craftId' => $field->id,
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('site7-studio', 'Shared Resource registered.'));
        return $this->redirectToPostedUrl();
    }

    /**
     * Downloads a JSON snapshot of the Shared Resource's live definition -
     * for a field/matrix, its field-layout shape via
     * CraftResourceService::describeField()/describeFieldLayout(). This is a
     * live-resource snapshot, not a package archive - no zip/PackageExportService
     * involved, since a Shared Resource isn't a package folder on disk.
     */
    public function actionExport(string $handle)
    {
        $record = Site7Studio::getInstance()->sharedResourceRegistry->getByHandle($handle);
        if (!$record) {
            throw new \yii\web\NotFoundHttpException('Shared Resource not found.');
        }

        $snapshot = [
            'handle' => $record->handle,
            'name' => $record->name,
            'type' => $record->type,
            'craftUid' => $record->craftUid,
            'version' => $record->version,
        ];

        $field = Craft::$app->getFields()->getFieldByHandle($handle);
        if ($field) {
            $snapshot['field'] = Site7Studio::getInstance()->craftResourceGenerator->describeField($field);
        }

        $response = Craft::$app->getResponse();
        $response->format = \yii\web\Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$handle}.json\"");
        $response->data = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $response;
    }

    /**
     * Refreshes a registry row's cached metadata from the live Craft field -
     * mirrors MarketplaceService::repairPackage()'s "resync from reality"
     * role, for Shared Resources instead of packages.
     */
    public function actionUpdate()
    {
        $this->requirePostRequest();

        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');
        $record = Site7Studio::getInstance()->sharedResourceRegistry->getByHandle($handle);
        if (!$record) {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Shared Resource not found.'));
            return $this->redirectToPostedUrl();
        }

        $field = Craft::$app->getFields()->getFieldByHandle($handle);
        if (!$field) {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', "'{$handle}' no longer exists in Craft - it cannot be refreshed."));
            return $this->redirectToPostedUrl();
        }

        Site7Studio::getInstance()->sharedResourceRegistry->registerIfMissing([
            'handle' => $field->handle,
            'name' => $field->name,
            'type' => $record->type,
            'craftUid' => $field->uid,
            'craftId' => $field->id,
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('site7-studio', 'Shared Resource refreshed.'));
        return $this->redirectToPostedUrl();
    }

    /**
     * Deletes a Shared Resource's registry row (never the live Craft
     * resource itself) - blocked whenever it's still referenced by a
     * package or another Shared Resource, mirroring
     * PackageActionController::actionDelete()'s usage-protection exactly.
     */
    public function actionDelete()
    {
        $this->requirePostRequest();

        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');
        $usageService = Site7Studio::getInstance()->sharedResourceUsage;
        $usage = $usageService->getUsage($handle);

        if (!$usageService->isEmpty($usage)) {
            $names = array_merge(
                array_map(fn($p) => $p->name, $usage['packages']),
                array_map(fn($r) => $r->name, $usage['sharedResources']),
            );
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Cannot delete Shared Resource. It is still referenced by: ' . implode(', ', $names)));
            return $this->redirectToPostedUrl();
        }

        try {
            Site7Studio::getInstance()->sharedResourceRegistry->delete($handle);
            Craft::$app->getSession()->setNotice(Craft::t('site7-studio', 'Shared Resource deleted from the registry.'));
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());
        }

        return $this->redirectToPostedUrl();
    }
}
