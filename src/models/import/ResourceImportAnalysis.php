<?php

namespace site7\studio\models\import;

use craft\base\Model;

/**
 * The result of ResourceAnalyzerService::analyze*() - a pure, read-only
 * preview of what importing a live Craft resource would produce, computed
 * before anything is written to @packages. Mirrors the {valid, errors,
 * warnings} shape of site7\studio\models\publishing\PublishReadinessResult,
 * but is kept as a sibling model rather than a subclass - that one measures
 * "is this already-generated package ready to publish", this one measures
 * "is this live Craft resource ready to be generated into a package", which
 * are different questions asked at different points in the workflow.
 */
class ResourceImportAnalysis extends Model
{
    /** True only when $errors is empty - the wizard's Save button is disabled otherwise. */
    public bool $valid = false;

    /** One of: matrix-entry-type, craft-section, page, website. */
    public string $kind = '';

    /** Human-readable label for the analyzed source, shown as the Preview step's header. */
    public string $sourceLabel = '';

    /**
     * Phase 16: each entry is CraftResourceService::describeField()'s output
     * enriched by ResourceClassifierService::classifyField() -
     * {handle, name, type, instructions, supported, fieldClass, classification,
     * action, statusLabel, detail[, requiredPlugin]}. classification is one of
     * ResourceClassifierService's six buckets; action is one of
     * import/reference/report-dependency/report-missing-plugin - never a silent skip.
     * @var array<int, array<string, mixed>>
     */
    public array $detectedFields = [];

    /** @var array<int, array{id: int, handle: string, name: string}> Populated for craft-section/website kinds. */
    public array $detectedEntryTypes = [];

    /** @var array<int, array{kind: string, handle: string, status: string}> status: installed|missing|not-packaged */
    public array $detectedDependencies = [];

    /** The full manifest.json this import would write, for display only - never written from here. */
    public array $proposedManifest = [];

    public string $proposedHandle = '';

    /** @var string[] Read-only thumbnail URLs shown for context; never imported as package assets. */
    public array $previewImageUrls = [];

    /** Estimated on-disk size of the generated package, in bytes. */
    public int $packageSizeEstimate = 0;

    /** @var string[] Fatal problems that block the Save step. */
    public array $errors = [];

    /** @var string[] Non-fatal issues shown to the user but that don't block Save. */
    public array $warnings = [];

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['valid'], 'boolean'];
        $rules[] = [['kind', 'sourceLabel', 'proposedHandle'], 'string'];
        $rules[] = [['packageSizeEstimate'], 'integer', 'min' => 0];
        $rules[] = [[
            'detectedFields', 'detectedEntryTypes', 'detectedDependencies',
            'proposedManifest', 'previewImageUrls', 'errors', 'warnings',
        ], 'safe'];
        return $rules;
    }
}
