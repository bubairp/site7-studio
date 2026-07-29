# Phase 2 — Native Resource Capture (Volumes, Categories/Tags, Section Settings) + CraftResourceScanner Refactor

Status: **Implemented.** Per `WEBSITE-STARTER-KIT-SYSTEM.md` Phase 2 - read-only capture into the Phase 1 schema, no install-side changes. Ahead of Phase 2, this also introduces `CraftResourceScanner`, the single discovery layer requested for all future phases (Project Builder, Dependency Analyzer, Blueprint Builder, Starter Kit Builder, Starter Kit Installer).

## CraftResourceScanner refactor

Before Phase 2, native Craft resource discovery was scattered: `WebsiteImportService`, `CraftResourceDiscoveryService`, and `ResourceImportController` each called `Craft::$app->get*()` directly, and the "is this field a navigation plugin field" heuristic existed only inside `CraftResourceDiscoveryService`.

New structure:

- `src/interfaces/ResourceScannerInterface.php` - one method, `scan(): array`. A scanner's only job is to answer "what exists on this site of this kind" using live Craft objects; it never classifies, transforms, or shapes data into a manifest (that stays the job of `ResourceClassifierService`, `CraftResourceService::describeFieldLayout()`, and the `*ImportService` classes, which now consume a scanner's output instead of querying Craft directly).
- `src/services/scanning/*.php` - ten dedicated scanners, one per resource kind: `SectionScanner`, `EntryTypeScanner`, `FieldScanner`, `MatrixFieldScanner`, `AssetVolumeScanner`, `CategoryGroupScanner`, `TagGroupScanner`, `GlobalSetScanner`, `NavigationScanner`, `PluginScanner`. Each wraps exactly one Craft service (`getAllSections()`, `getAllVolumes()`, etc.) plus `findByHandle()`/`findByUid()`/`findById()` lookups where Craft offers them.
  - `NavigationScanner` centralizes the "is this a `remoteprogrammer\simplerpmenu` field" prefix check that used to live only inside `CraftResourceDiscoveryService::isNavigationField()`; that method now delegates to it.
  - `MatrixFieldScanner::entryTypeUsageMap()` centralizes the "which Entry Types does each Matrix field allow" fan-out map that `CraftResourceDiscoveryService::buildMatrixFieldsByEntryTypeMap()` used to build itself.
- `src/services/CraftResourceScanner.php` - a thin facade Component holding one instance of each sub-scanner (public properties, lazily defaulted in `init()`), exposing `scanSections()`/`scanEntryTypes()`/`scanFields()`/`scanMatrixFields()`/`scanAssetVolumes()`/`scanCategoryGroups()`/`scanTagGroups()`/`scanGlobalSets()`/`scanNavigation()`/`scanPlugins()`. Registered on the plugin service locator as `craftResourceScanner` (`CoreServiceProvider`), and directly `new`-able like the other import-era services.
  - Every sub-scanner property is public and Yii-config-constructible (`new CraftResourceScanner(['sectionScanner' => $fake])`), so a future test can substitute a fake per resource kind without a live Craft app.

**Migrated onto the scanner** (the "avoid scattering discovery logic" ask): `CraftResourceDiscoveryService` (`discoverEntryTypes()`, `getMatrixFields()`, `buildMatrixFieldsByEntryTypeMap()`, `getEntryTypeDetail()`, `resolveEntriesFieldSections()`, `isNavigationField()`, `getSite7MatrixFieldHandle()` all now go through `$this->scanner`) and `ResourceImportController` (`actionGetCraftSections()`/`actionGetWebsiteResources()`'s section/global-set/category-group/tag-group listings). Behavior is unchanged - see Live verification below.

## Phase 2 capture (WebsiteImportService)

`importWebsite()` now also captures, referenced-only (never a blanket project-wide dump - the same "capture what's used" boundary as the existing `sharedResourceHandles` pattern):

- **Craft Section settings** for every Section a selected page belongs to.
- **Asset Volume settings** for every Volume a selected page's or Global Set's Assets field can select from.
- **Category Group** / **Tag Group settings** for every group a selected page's or Global Set's Categories/Tags field can select from. The field's own linked *values* are still not captured (unchanged: recorded as a "links will be empty on install" note) - only the Group's own definition is now captured, so the target site has somewhere to assign values into after a future install phase.

Mechanics:
- A field's `sources` setting (`craft\fields\Assets::$sources`, etc.) is resolved via the same `'volume:{uid}'`/`'group:{uid}'`/`'taggroup:{uid}'` source-string convention Craft core itself uses (mirrors the existing `'section:{uid}'` handling in `CraftResourceDiscoveryService::resolveEntriesFieldSections()`). A field set to allow **all** sources (`sources === '*'`) resolves to every resource of that kind project-wide via `CraftResourceScanner`, matching how Craft treats that field at query time (no restriction) - this is the common case in the Base Project (every Assets/Categories/Tags field sampled during live verification uses `'*'`).
- This parsing (`WebsiteImportService::collectRelationSourceUids()`) is a static, Craft-app-independent method specifically so it has a real unit test (`WebsiteImportServiceTest`) despite everything else in this service needing a live Craft app.
- Referenced uids are deduplicated (an `[uid => true]` map) across every selected page and Global Set before being resolved to live objects and shaped into the manifest - a Volume/Group referenced by five different pages appears once.

## Manifest schema (PackageManifest)

`assetVolumes`/`categoryGroups`/`tagGroups` already existed as empty-array placeholders from Phase 1; this phase gives them their concrete shape and adds a new `craftSections` field:

- `assetVolumes[]`: `{handle, name, fsHandle, transformFsHandle, transformSubpath, titleTranslationMethod}`. No `siteSettings` - Volumes have none.
- `categoryGroups[]`: `{handle, name, maxLevels, defaultPlacement, siteSettings: [{siteHandle, hasUrls, uriFormat, template}]}`.
- `tagGroups[]`: `{handle, name}` - Tag Groups have no per-site settings.
- `craftSections[]` (new): `{handle, name, type, propagationMethod, enableVersioning, maxLevels, defaultPlacement, siteSettings: [{siteHandle, enabledByDefault, hasUrls, uriFormat, template}]}`. Section-level settings only - the Section's Entry Types/field layouts are still captured exactly as before via `entryFields`/`requires`; nothing here duplicates that.

A subtlety caught during live verification, not by the unit tests: Craft's `getSiteSettings()` (on both `Section` and `CategoryGroup`) is keyed by site ID, not reindexed from 0 - `array_map()` over it preserves those keys, so `json_encode()` was serializing `siteSettings` as a JSON **object** (`{"1": {...}}`) instead of the documented array shape on a single-site project. Fixed by wrapping both call sites in `array_values()` before mapping.

## Tests

- `tests/unit/services/scanning/NavigationScannerTest.php` (new) - pure prefix-matching logic, no live field object needed (extracted to `NavigationScanner::classNameMatchesNavigationPrefix()` specifically for this).
- `tests/unit/services/import/WebsiteImportServiceTest.php` (new) - `collectRelationSourceUids()`'s source-string parsing via reflection (prefixed sources, wrong-prefix sources, `null`, wildcard, merge-not-overwrite).
- `tests/unit/models/packages/PackageManifestTest.php` (extended) - `craftSections` added to both the legacy-manifest-still-validates case and the round-trip case.

This host has no PHPUnit/Codeception binary installed (`phpunit.xml.dist` exists but the runner isn't vendored here or in the DDEV container), so every assertion above was additionally hand-verified by directly invoking the same classes/methods through a `Yii`-bootstrapped PHP one-liner, matching all expected values.

## Live verification

Two DDEV passes, both with temporary fixtures/packages cleaned up immediately after (no lasting change to the dev site or its packages):

1. **Scanner refactor correctness** - `CraftResourceScanner`'s ten `scan*()` methods were called against the real Base Project site and cross-checked against the equivalent direct `Craft::$app->get*()->getAll*()` call: Sections 35, Entry Types 109, Fields 166, Matrix Fields 39, Asset Volumes 1, Category Groups 2, Tag Groups 1, Global Sets 0, Navigation fields 1 (the real `remoteprogrammer/simplerpmenu` field noted in the Phase 16 architecture doc), Plugins 21 - all matched exactly. `CraftResourceDiscoveryService::discoverEntryTypes()`/`getMatrixFields()` (now migrated onto the scanner) were also re-run and produced identical counts to before the migration.
2. **Phase 2 capture correctness** - `WebsiteImportService::importWebsite()` was run against real Entry #8293 ("Portfolio 06", section `portfolios`), which has Assets/Categories/Tags fields all configured with `sources: '*'` - the case that exercises every new code path at once. Resulting `manifest.json` contained: `craftSections` with the real `portfolios` Section's actual `propagationMethod`/`uriFormat`/`template`/site settings; `assetVolumes` with the project's one real Volume (`publicAsset`, `fsHandle: public`); `categoryGroups` with **both** real Category Groups (`blogCategories` and `portfolioCategories` - correct wildcard-resolves-to-all behavior); `tagGroups` with the real `web` Tag Group. The pre-existing "links will be empty on install" notes for the Category/Tag fields were still produced unchanged. The created Starter Kit and its required Template package were deleted via `PackageManagerService::deletePackage()` immediately after (confirmed via `git status` on `packages/` showing no residue).

## Deferred to later phases

Nested schema validation of the new shapes, Navigation capture (Phase 3 - `NavigationScanner` exists but nothing calls it from an import service yet), frontend tooling/npm/plugin capture (Phase 4), and any install-side consumption of `craftSections`/`assetVolumes`/`categoryGroups`/`tagGroups` beyond the existing `globals` install path (Phase 6+).
