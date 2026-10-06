<?php

namespace site7\studio\console\controllers;

use Craft;
use craft\console\Controller;
use craft\fields\Matrix;
use site7\studio\repositories\SectionImportSourceRepository;
use site7\studio\services\import\MatrixEntryTypeImportService;
use site7\studio\Site7Studio;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Bulk imports into the Library (docs/14_IMPORT_EXISTING_SECTION.md).
 */
class ImportController extends Controller
{
    /** sections: list what would be imported without importing anything. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'sections') {
            $options[] = 'dryRun';
        }
        return $options;
    }

    /**
     * Imports every block type (Entry Type) of the configured page-builder
     * Matrix field as a Section package, one by one through Import Existing
     * Section. Block types already in the Library are skipped; a failure on
     * one doesn't stop the rest.
     * Usage: php craft site7-studio/import/sections [--dry-run]
     */
    public function actionSections(): int
    {
        $fieldId = Site7Studio::getInstance()->getSettings()->matrixFieldId;
        $field = $fieldId ? Craft::$app->getFields()->getFieldById($fieldId) : null;
        if (!$field instanceof Matrix) {
            $this->stderr("No page-builder Matrix field is configured - choose one in Site7 Studio > Settings > General first.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $entryTypes = $field->getEntryTypes();
        $this->stdout("Field '{$field->handle}': " . count($entryTypes) . " block types" . ($this->dryRun ? ' (dry run)' : '') . "\n\n");

        $sources = new SectionImportSourceRepository();
        $importer = new MatrixEntryTypeImportService();
        $counts = ['imported' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($entryTypes as $entryType) {
            $label = str_pad($entryType->handle, 26);

            if ($source = $sources->findBySourceUid($entryType->uid)) {
                $this->stdout("  skip    {$label} already in the Library\n", Console::FG_GREY);
                $counts['skipped']++;
                continue;
            }

            if ($this->dryRun) {
                $template = is_file(Craft::getAlias('@templates') . "/_blocks/{$entryType->handle}.twig") ? 'template found' : 'NO _blocks template';
                $this->stdout("  import  {$label} {$entryType->name} - " . count($entryType->getFieldLayout()->getCustomFields()) . " fields, {$template}\n");
                continue;
            }

            try {
                $record = $importer->importFromEntryType($entryType->id, ['name' => $entryType->name]);
                $manifest = $record->getManifest();
                $shared = count($manifest?->dependencies['sharedResources'] ?? []);
                $excluded = count($manifest?->excludedFields ?? []);
                $this->stdout("  ok      {$label} -> {$record->handle} ({$shared} shared, {$excluded} excluded)\n", Console::FG_GREEN);
                $counts['imported']++;
            } catch (\Throwable $e) {
                $this->stdout("  FAILED  {$label} {$e->getMessage()}\n", Console::FG_RED);
                $counts['failed']++;
            }
        }

        $this->stdout("\nImported {$counts['imported']}, skipped {$counts['skipped']}, failed {$counts['failed']}.\n");

        return $counts['failed'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
