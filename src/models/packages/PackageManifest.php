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

    /**
     * Phase 16 - the Shared Resource Registry's manifest-side contract:
     * {sharedResources: string[], pluginDependencies: [{handle, requiredPlugin}]}.
     * Never embeds a Shared Resource's own definition - only references it by
     * handle, resolved against site7_shared_resources at install time
     * (DependencyResolverService). Distinct from $requires, which is the
     * existing (frozen) Section/Pattern/Template graph.
     *
     * Website Starter Kit System schema additions, reserved empty by Phase 1
     * and populated by Phase 4's environment capture (ComposerDependencyScanner/
     * FrontendToolingScanner, wired into WebsiteImportService) - no install
     * logic reads these yet:
     * - plugins: [{handle, package, versionConstraint}] - every installed
     *   Craft plugin project-wide (not scoped to captured content - this is
     *   a whole-environment snapshot), distinct from the reporting-only
     *   pluginDependencies above (which only flags a missing plugin against
     *   one captured field; it is never installed). A future install phase
     *   will use this list to actually require/enable plugins on the target
     *   site. versionConstraint comes from the project's own composer.json
     *   `require`/`require-dev`, not the plugin's own declared version.
     * - npmPackages: [{name, version, dev}] - captured package.json
     *   dependency entries (dependencies + devDependencies, dev
     *   distinguishing them), from whichever directory FrontendToolingScanner
     *   found a package.json in (project root, or a conventional
     *   subdirectory like frontend/).
     * - frontendTooling: {system: string, configFiles: string[]} - the
     *   detected build system identifier ('vite'/'webpack'/'gulp'/'tailwind'/
     *   'plain') plus the config file paths captured (relative to the
     *   frontend root) - config content only, never node_modules/build
     *   output. The actual file contents are copied into this package's own
     *   directory (see WebsiteImportService::copyFrontendConfigFiles()) so
     *   the existing package archive machinery (PackageArchiveHelper) ships
     *   them inside the .s7pkg automatically, with no changes needed there.
     *   Empty/absent on any package that hasn't gone through Phase 4
     *   capture, or where no package.json was found at all.
     */
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
     * The source Section's own type ('single'|'channel'|'structure', craft\models\
     * Section::TYPE_*) at capture time - lets the Library list/detail views show
     * what kind of page this Template came from, and lets "Create from Template"
     * (TemplateInsertionService::getEligibleEntryTypes()) reason about it without
     * re-resolving the (possibly since-deleted) source Section on every request.
     */
    public ?string $sourceSectionType = null;

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

    // --- Craft Resource Import metadata (Phase 15) ---
    // Optional and additive, same rationale as the Phase 14 block above -
    // absent/empty on any package that wasn't produced by the Resource
    // Importer, including every package written before this phase existed.

    /**
     * Set only on packages produced by the Resource Importer:
     * {sourceType: 'matrix-entry-type'|'craft-section'|'entry'|'website', sourceId,
     * sourceHandle, importedAt, importedBy}. Never a live, resolvable reference - the
     * source Craft resource may since have been renamed or removed.
     */
    public array $importedFrom = [];

    /**
     * Starter Kit imports only: captured Global Set field values, one entry per
     * selected Global Set - {globalSetHandle, name, fields: {handle: value}}.
     */
    public array $globals = [];

    /**
     * Fields the Resource Importer detected on the source but did not
     * capture into this package - Platform Configuration and Unknown
     * Resource classified fields (Shared Resource and Plugin Dependency
     * fields are recorded separately, in $dependencies). Recorded purely so
     * the Package Editor can show "detected but not imported" instead of
     * these fields disappearing without a trace once the source's own
     * Analyze/Preview step is gone. Each entry is {handle, name, type,
     * classification, detail}.
     */
    public array $excludedFields = [];

    // --- Website Starter Kit System schema foundations (Phase 1) ---
    // Schema-only for now: no import/capture or install-side code reads or
    // writes these yet. They exist so later phases (2-8) have a stable
    // manifest surface to build capture/install logic against without
    // another round of schema churn. All optional and additive, same
    // rationale as the Phase 14/15 blocks above.

    /**
     * Craft Asset Volume settings captured from the source site (Phase 2:
     * only Volumes actually referenced by a captured Assets field are
     * recorded - never a blanket dump of every Volume on the project,
     * matching the existing "capture what's referenced" pattern already
     * used for Shared Resource fields). Each entry is {handle, name,
     * fsHandle, transformFsHandle, transformSubpath, titleTranslationMethod}.
     * The Volume's own filesystem definition (config/project/fs/*) and its
     * Assets are never captured - installing a Starter Kit that references a
     * Volume handle missing on the target site is a capture-time note today
     * (WebsiteImportService), not an auto-created Volume; that remains out
     * of scope until an install phase says otherwise. Volumes have no
     * per-site settings (unlike Sections/Category Groups), so there is no
     * siteSettings sub-array here.
     */
    public array $assetVolumes = [];

    /**
     * Craft Category Group settings captured from the source site (Phase 2:
     * referenced-only, same rationale as $assetVolumes). Each entry is
     * {handle, name, maxLevels, defaultPlacement, siteSettings:
     * [{siteHandle, hasUrls, uriFormat, template}]}.
     */
    public array $categoryGroups = [];

    /**
     * Craft Tag Group settings captured from the source site (Phase 2:
     * referenced-only, same rationale as $assetVolumes). Each entry is
     * {handle, name}. Tag Groups have no per-site settings or field layout
     * distinct from an ordinary Craft field layout capture, so this is
     * intentionally thinner than $categoryGroups/$craftSections.
     */
    public array $tagGroups = [];

    /**
     * Craft Section-level settings captured from the source site for every
     * Section referenced by a captured page (Phase 2). Each entry is
     * {handle, name, type, propagationMethod, enableVersioning, maxLevels,
     * defaultPlacement, siteSettings: [{siteHandle, enabledByDefault,
     * hasUrls, uriFormat, template}]}. This is Section-level configuration
     * only - the Section's own Entry Types/field layouts are still captured
     * exactly as before, via $entryFields/$requires; nothing here duplicates
     * that.
     */
    public array $craftSections = [];

    /**
     * Captured site navigation, intended to replace the current
     * structure-entry-nesting approximation (Phase 3). Shape is
     * intentionally unspecified until Phase 3 defines the real Navigation
     * model - reserved here only so Phase 1's consumers have a stable key
     * to read an empty array from.
     */
    public array $navigation = [];

    /**
     * Allow-listed Project Config paths (e.g. 'sections.*', 'fields.*')
     * relevant to this package, for the future diff/apply mechanism
     * (Phase 6). Not a full Project Config dump - see Phase 4/6 for the
     * bundling and apply strategy.
     */
    public array $projectConfigPaths = [];

    /**
     * Explicit package-owned files beyond the type-specific ones already
     * captured elsewhere (template.twig, fields.yaml, matrix.yaml) - Step
     * 8.1, Package-Owned File Model. Each entry is
     * {sourcePath, targetPath, type}:
     *   - sourcePath: relative to this package's own directory (e.g.
     *     'frontend/src/css/components/ctaBanner.css') - where the file's
     *     actual content lives inside packages/{handle}/, shipped inside
     *     the .s7pkg automatically like any other file already there (see
     *     PackageArchiveHelper::addDirectoryToZip() - a generic recursive
     *     copy, no export-side change needed for this).
     *   - targetPath: relative to the Craft install root (e.g. the same
     *     'frontend/src/css/components/ctaBanner.css') - where it belongs
     *     on the consuming site, the same "preserve the real relative
     *     runtime path" rule templates/_blocks/{handle}.twig already
     *     follows. sourcePath and targetPath are identical in every case
     *     produced today (the real relative path is mirrored verbatim,
     *     never flattened/renamed) - kept as two separate keys rather than
     *     one so a future case where they genuinely need to differ doesn't
     *     require a schema change.
     *   - type: free-text category ('frontend-css'|'frontend-js'|'asset')
     *     for display/filtering only - nothing in the install/update/
     *     rollback pipeline branches on it as of Step 8.1.
     * Never populated by automatic scanning - a file only appears here
     * because a human explicitly selected it during Import Existing
     * Section (MatrixEntryTypeImportService::captureOwnedFiles()). Empty
     * on every package created before Step 8.1, and on any package whose
     * author selected no additional files (the common case - see the Step
     * 8A audit: most components in this project own no dedicated CSS/JS at
     * all, styled instead through Tailwind utilities and shared global
     * frontend files that no single package should claim ownership of).
     *
     * @var array<int, array{sourcePath: string, targetPath: string, type: string}>
     */
    public array $ownedFiles = [];

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['type', 'handle', 'name', 'version', 'schemaVersion'], 'required'];
        $rules[] = [['type', 'handle', 'name', 'version', 'schemaVersion', 'author', 'description', 'category', 'preview', 'sourceEntryType', 'sourceSection', 'sourceSectionType'], 'string'];
        $rules[] = [['compatibility', 'dependencies', 'tags', 'requires', 'demoContent', 'entryFields', 'pages', 'keywords', 'importedFrom', 'globals', 'excludedFields', 'assetVolumes', 'categoryGroups', 'tagGroups', 'craftSections', 'navigation', 'projectConfigPaths', 'ownedFiles'], 'safe'];
        $rules[] = [['displayName', 'company', 'website', 'supportUrl', 'documentationUrl', 'license', 'pricingType', 'minimumCraftVersion', 'minimumSite7Version'], 'string'];
        return $rules;
    }
}
