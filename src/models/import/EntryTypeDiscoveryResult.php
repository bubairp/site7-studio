<?php

namespace site7\studio\models\import;

use craft\base\Model;

/**
 * The result of CraftResourceDiscoveryService::discoverEntryTypes()/
 * getEntryTypeDetail() for a single Matrix Entry Type - Phase 17's
 * Entry-Type-level classification, distinct from Phase 16's field-level
 * ResourceClassifierService (which this model's $dependencies/$warnings are
 * built from, as an input signal, not a duplicate of).
 */
class EntryTypeDiscoveryResult extends Model
{
    public int $id = 0;
    public string $handle = '';
    public string $name = '';

    /** Always 'Entry Type' today - kept as a field for forward compatibility with other Craft resource kinds. */
    public string $craftResourceType = 'Entry Type';

    /**
     * One of CraftResourceDiscoveryService's six buckets: presentation-section,
     * feature-component, shared-resource, utility-component, plugin-component,
     * unknown.
     */
    public string $classification = 'unknown';

    /** 0-100. See CraftResourceDiscoveryService's scoring docblock. */
    public int $confidence = 0;

    /**
     * True when confidence is too low, or too close to a runner-up bucket, to
     * trust automatically - the classification above is still the best guess,
     * never hidden, but must be visibly flagged rather than silently applied.
     */
    public bool $reviewRequired = false;

    /**
     * One of: Create Section Package, Convert to Shared Resource, Requires
     * Feature Bundle, Internal Utility, Manual Review.
     */
    public string $recommendation = 'Manual Review';

    /** How many Matrix fields project-wide list this Entry Type as an allowed block. */
    public int $usageCount = 0;

    /** @var array<int, array{handle: string, name: string}> Matrix fields (and packages, for the summary list) that reference this Entry Type. */
    public array $referencedBy = [];

    /**
     * @var array<int, array{kind: string, handle: string, name: string, detail: string}>
     * kind: shared-resource|plugin|craft-section|category|asset|navigation|template|unknown
     */
    public array $dependencies = [];

    /** @var string[] Human-readable warnings - every dependency/issue explained, never silently dropped. */
    public array $warnings = [];

    /** @var array<int, array{handle: string, requiredPlugin: string}> */
    public array $pluginRequirements = [];

    /** @var string[] Handles of Shared Resources this Entry Type references or itself qualifies as. */
    public array $sharedResources = [];

    /** Rough estimated on-disk size (bytes) if this were generated into a package - browsing-time estimate only. */
    public int $estimatedPackageSize = 0;

    /** @var array<int, array{handle: string, name: string, type: string, classification: string, statusLabel: string}> Per-field classification detail, for the Resource Detail view. */
    public array $fields = [];

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['id', 'confidence', 'usageCount', 'estimatedPackageSize'], 'integer'];
        $rules[] = [['reviewRequired'], 'boolean'];
        $rules[] = [['handle', 'name', 'craftResourceType', 'classification', 'recommendation'], 'string'];
        $rules[] = [['referencedBy', 'dependencies', 'warnings', 'pluginRequirements', 'sharedResources', 'fields'], 'safe'];
        return $rules;
    }
}
