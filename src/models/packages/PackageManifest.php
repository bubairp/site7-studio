<?php

namespace site7\studio\models\packages;

use craft\base\Model;

/**
 * Represents the data defined inside a package's manifest.json.
 */
class PackageManifest extends Model
{
    public string $schemaVersion = '1';
    public string $type = 'section';
    public string $handle = '';
    public string $name = '';
    public string $version = '1.0.0';
    public string $author = '';
    public string $description = '';
    public ?string $category = null;
    public array $tags = [];
    public array $compatibility = [];
    public array $dependencies = [];
    public array $requires = [];
    public array $demoContent = [];
    public ?string $preview = null;

    /**
     * The handle of the Entry Type/Section a Template was generated from ("Save as
     * Template"), kept as structural identity only - never a runtime ID. Used to
     * pre-select a matching option in the "Create from Template" wizard when one is
     * still installed; the editor can always choose a different Entry Type instead.
     */
    public ?string $sourceEntryType = null;
    public ?string $sourceSection = null;

    /**
     * The source Entry's own custom field values (i.e. its Section/Entry Type field
     * layout), keyed by field handle - everything except the Site7 Matrix field
     * itself, which is captured separately via demoContent/requires.
     */
    public array $entryFields = [];

    /**
     * Starter Kit only: the pages that make up the captured site, in order.
     * Each entry is {title, slug, sectionHandle, entryTypeHandle, templateHandle} -
     * structural identity plus a reference to the Template package that holds the
     * actual content, per the "never duplicate Templates inside the Starter Kit"
     * rule. Installing a Starter Kit replays this list through the existing
     * Create-from-Template mechanism rather than storing page content twice.
     */
    public array $pages = [];

    // --- Package Publishing metadata (Phase 14) ---
    // All optional and additive - a manifest.json written before Phase 14
    // still parses fine with these simply defaulting to null/empty, and
    // nothing else in the Package Engine reads or requires them.

    /** A friendlier name than the internal $name, for marketplace/catalog display. Falls back to $name when blank. */
    public ?string $displayName = null;
    public ?string $company = null;
    public ?string $website = null;
    public ?string $supportUrl = null;
    public ?string $documentationUrl = null;

    /** e.g. 'MIT', 'GPL-3.0', 'Proprietary' - free text, not validated against an SPDX list. */
    public ?string $license = null;

    /** One of PackagePublisher::PRICING_TYPES - see that interface/service for the fixed handle list. */
    public string $pricingType = 'free';

    public ?string $minimumCraftVersion = null;
    public ?string $minimumSite7Version = null;

    /** @var string[] Search/discovery keywords, distinct from $tags (which drive Library filtering). */
    public array $keywords = [];

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['type', 'handle', 'name', 'version', 'schemaVersion'], 'required'];
        $rules[] = [['type', 'handle', 'name', 'version', 'schemaVersion', 'author', 'description', 'category', 'preview', 'sourceEntryType', 'sourceSection'], 'string'];
        $rules[] = [['compatibility', 'dependencies', 'tags', 'requires', 'demoContent', 'entryFields', 'pages', 'keywords'], 'safe'];
        $rules[] = [['displayName', 'company', 'website', 'supportUrl', 'documentationUrl', 'license', 'pricingType', 'minimumCraftVersion', 'minimumSite7Version'], 'string'];
        return $rules;
    }
}
