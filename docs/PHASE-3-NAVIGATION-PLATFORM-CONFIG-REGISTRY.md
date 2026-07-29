# Phase 3 — Navigation, Platform Configuration & CraftResourceRegistry

Status: **Implemented.** Per `WEBSITE-STARTER-KIT-SYSTEM.md` Phase 3, preceded by the requested `CraftResourceRegistry` refactor that every later phase (Project Builder, Dependency Analyzer, Blueprint Builder, Starter Kit Builder, Starter Kit Installer, Sync Engine) should build against.

## CraftResourceRegistry

Sits between `CraftResourceScanner` (Phase 2 - discovers native Craft resources, no memory between calls) and every higher-level service. Responsibility split, kept strict:

- **CraftResourceScanner** - discovers. Ten dedicated scanners, one Craft API call surface each.
- **CraftResourceRegistry** - stores what was discovered for the current session, indexes it by uid/handle/type, and resolves cross-resource references into a single graph. Never mutates a Craft resource.

New files:

- `src/models/registry/ResourceNode.php` - a thin wrapper: `{kind, key, handle, name, resource}`, where `resource` is the *actual* live Craft object (Section/EntryType/FieldInterface/Volume/CategoryGroup/TagGroup/GlobalSet/PluginInterface) the Scanner returned - never a copy.
- `src/models/registry/ResourceGraph.php` - the in-memory graph: node storage (indexed by handle and uid separately), directed "depends on" edges + their reverse, and:
  - `getByHandle()` / `getByUid()` / `all($kind)`
  - `dependenciesOf($node)` / `dependentsOf($node)` - one-hop traversal
  - `topologicalOrder()` - a dependency-first ordering (Kahn's algorithm) for the whole graph. A cycle (see below) can't be fully ordered; unresolved nodes are appended afterward in insertion order rather than throwing - every node still appears exactly once, which is what a future installer actually needs.
- `src/services/registry/ResourceGraphBuilder.php` - single-responsibility graph construction from `CraftResourceScanner`'s output, kept out of the Registry facade itself (same split as the Scanner/its ten sub-scanners). Edges recorded: Section→EntryType, EntryType/GlobalSet→Field (field layout), Field(Assets)→AssetVolume, Field(Categories)→CategoryGroup, Field(Tags)→TagGroup, Field(Entries)→Section, Field(Matrix)→EntryType (block types), Navigation(virtual)→Field.
- `src/services/CraftResourceRegistry.php` - the facade: `getGraph($force=false)` (session-cached; scans Craft once per instance), `reset()`, `findByHandle()`, `findByUid()`, `all()`, `dependenciesOf()`, `dependentsOf()`, `installOrder()`. Registered as `craftResourceRegistry`.

  Named `getGraph()` rather than the more obvious `load()` - `craft\base\Component` (every service in this plugin extends it) descends from `yii\base\Model`, which already declares an incompatible `load($data, $formName)` for populating a model from POST data. This was caught by a PHP compile error during live verification, not by any unit test (nothing here needed a live Craft app to hit it, but nothing exercised the real class hierarchy before then either).

`src/services/scanning/RelationFieldSourceResolver.php` (new) - the `'volume:{uid}'`/`'group:{uid}'`/`'taggroup:{uid}'`/`'section:{uid}'` source-string parsing extracted out of `WebsiteImportService` (previously a private static method there), so both the graph builder and `WebsiteImportService` share one implementation. Static, Craft-app-independent, directly unit-tested.

### Migrated onto the Registry

Every place that used to call `CraftResourceScanner` (or Craft directly) for something that's really a relationship lookup now goes through `CraftResourceRegistry` instead:

- `CraftResourceDiscoveryService` - `discoverEntryTypes()`, `getEntryTypeDetail()`, `getMatrixFields()`, `buildMatrixFieldsByEntryTypeMap()` (now reads the graph's Field→EntryType edges via `dependentsOf()` instead of a separate dedicated map), `resolveEntriesFieldSections()`, `isNavigationField()`.
- `WebsiteImportService` - see below; this is the biggest beneficiary.
- `ResourceImportController` - `actionGetCraftSections()`/`actionGetWebsiteResources()`'s Section/Global Set/Category Group/Tag Group listings.

`WebsiteImportService`'s Phase 2 capture logic (Asset Volume/Category Group/Tag Group settings referenced by a selected page or Global Set) no longer does its own per-field `instanceof` Assets/Categories/Tags branching + `sources` parsing at all. It now does pure graph traversal: `collectResourceDependencies($ownerNode, ...)` walks an Entry Type's or Global Set's Field dependencies one hop, then each Field's own Asset Volume/Category Group/Tag Group dependencies one more hop - edges the Registry already computed once when it built the graph. This was verified to produce byte-identical output to the pre-refactor Phase 2 capture (same live test: Entry #8293 "Portfolio 06").

## Navigation capture (replacing the Structure-nesting approximation)

`NavigationScanner::describeMenu(string $menuHandle): ?array` (new) resolves a Simple RP Menu field's selected value (the field stores nothing but the target menu's own `handle`) into the real menu: `{handle, name, items: [{name, order, parentOrder, entryRef, customUrl, target, isMegaMenu}]}`.

- **No hard dependency on the plugin.** Every touchpoint is defensive (`class_exists()`, dynamic `$plugin->get('simplerpmenu')` component access) - `site7-studio` has no `use remoteprogrammer\simplerpmenu\...` anywhere. A project without this plugin gets `null` back, not a fatal error.
- **An item's linked Entry (`entry_id` in the plugin's own schema) is resolved to `{sectionHandle, slug}`**, never kept as a raw element ID - matching this codebase's existing convention (`PackageManifest::$sourceEntryType`/`$sourceSection`'s docblock) that a captured reference must be portable structural identity, not a runtime ID that means nothing on a fresh target site.

`WebsiteImportService` now captures a real navigation menu (via `NavigationScanner::isNavigationField()` + `describeMenu()`) wherever a selected page's or Global Set's field layout has one selected, populating the previously-reserved `manifest->navigation[]` (Phase 1 left its shape undefined "until Phase 3"). Shape: `{fieldHandle, ownerType: 'page'|'globalSet', ownerHandle, source: 'simple-rp-menu', menu: {...}}`. The Structure-nesting approximation (`pages[].parentSlug`) is **still always recorded** regardless - it's the only signal at all on a project without this plugin, and costs nothing to keep - but a `$notes` entry says explicitly when a real menu was captured instead.

A real Craft 5 field-layout subtlety surfaced during live verification: this project's `footer` Entry Type places the *same* underlying `simpleRpMenu` field on its layout **four times** under different per-placement handle overrides (`footerMenu`, `footerMenuCol1`, `footerMenuCol2`, `footerMenuCol3` - one shared field uid, four independent values). `ResourceGraphBuilder` already keys everything by uid (never handle) for exactly this reason, so the graph and `dependentsOf()`/`dependenciesOf()` were unaffected; `WebsiteImportService`'s navigation capture correctly captured all four as independent menu selections (confirmed live, see below) since it always reads `$field->handle` from the live field-layout iteration rather than a hardcoded canonical handle.

## PlatformConfigService (replacing the placeholder heuristic)

`ResourceClassifierService` had a private `matchesPlatformSignal()`/`PLATFORM_SIGNAL_WORDS`, explicitly labeled in its own docblock as a "Placeholder heuristic pending a full PlatformConfigService (future phase)". `src/services/PlatformConfigService.php` (new) is that phase:

- Same underlying mechanism (signal-word substring matching against a field's handle - Craft has no native "this is site-wide config" flag to read), now grouped into categories (`CATEGORY_THEME`, `CATEGORY_TYPOGRAPHY`, `CATEGORY_SPACING`, `CATEGORY_CUSTOM_CODE`, `CATEGORY_ANIMATION`) instead of one flat bucket, and owned by a real, independently-testable, reusable service rather than a private classifier implementation detail.
- Every method is **static** and has zero Craft dependency, specifically so `ResourceClassifierService::classifyField()` - which must stay Craft-app-independent for its own existing unit test suite (`ResourceClassifierServiceTest`, which never bootstraps Craft) - can call `PlatformConfigService::categoryFor()` directly without needing `Site7Studio::getInstance()` or a live plugin instance. It's still registered as a Component (`platformConfig`) for consumers that prefer service-location.
- `ResourceClassifierService`'s `PLATFORM_CONFIGURATION` classification is unchanged; only the detail message is now category-specific ("Site-wide `theme` configuration value..." instead of a generic one). `ResourceClassifierServiceTest`'s existing platform-signal test only asserted the classification, not the detail text, so it's unaffected.

Full settings-backed Platform Configuration (a CP Settings screen, an actual theme/color/typography token registry - the Phase 16 architecture doc's "Group A") is still out of scope; this phase replaces the *implementation ownership and honesty* of the heuristic, not the heuristic's underlying sophistication.

## Resource dependency graph (Blueprint/install-order data model)

`CraftResourceRegistry::installOrder()` exposes the same `ResourceGraph::topologicalOrder()` a future Blueprint Builder/Starter Kit Installer will need: a dependency-first ordering across every discovered resource kind at once (Volumes/Groups → Fields → Entry Types/Global Sets → Sections, plugins and navigation included). Verified live against the real project:

- An unambiguously acyclic pair (`publicAsset` Volume → the `image` field that depends on it) orders correctly.
- The full graph's node count matches `installOrder()`'s output count exactly (no node lost or duplicated).
- A **genuine cycle exists in this real project** and was traced concretely, not assumed: `matrixContainer` (field) → `matrixRowET` (entry type) → `matrixRow` (field) → `matrixColumn` (entry type) → `matrixContent` (field, which allows dozens of entry types as blocks, including `portfolios`) → `portfolios` (entry type, which itself has a `matrixContainer` field) → back to the start. This is a real, intentional generic page-builder container/row/column system (per the Phase 16 architecture doc's own note on this exact building block) - "legal in Craft, since both are created together via Project Config rather than sequentially," precisely the case `ResourceGraph::topologicalOrder()`'s docblock was written to tolerate. `installOrder()` still includes every affected node exactly once; it just can't fully order the cyclic component, which is the documented, correct behavior rather than a defect.

## Tests

- `tests/unit/models/registry/ResourceGraphTest.php` (new) - node/edge storage, handle/uid lookup, `dependenciesOf()`/`dependentsOf()` as inverses, an edge to an unknown node being silently ignored, a correct topological order on an acyclic chain, and graceful (no-exception, no-lost-node) handling of a 2-node cycle.
- `tests/unit/services/scanning/RelationFieldSourceResolverTest.php` (new, replacing the now-obsolete `WebsiteImportServiceTest` whose target method moved here) - prefixed-source extraction, wrong-prefix sources, `null`, wildcard, and merge-not-overwrite.
- `tests/unit/services/PlatformConfigServiceTest.php` (new) - every signal word resolves to its documented category, case-insensitivity, a non-matching handle returns `null`/`false`, `describe()`'s shape.

This host still has no PHPUnit/Codeception binary (`phpunit.xml.dist` exists but isn't vendored here or in the DDEV container), so every assertion above was additionally hand-verified by directly invoking the same classes/methods through a `Yii`-bootstrapped PHP one-liner, matching all expected values.

## Live verification

All against the real DDEV site, with every temporary package/fixture cleaned up immediately after (confirmed via `git status` on `packages/` showing no residue):

1. **Registry node counts** - all ten resource kinds cross-checked against the equivalent direct Craft API call; all matched (35 Sections, 109 Entry Types, 166 Fields, 1 Asset Volume, 2 Category Groups, 1 Tag Group, 0 Global Sets, 21 Plugins).
2. **Relationship correctness** - `portfolios` Section → `portfolioET` Entry Type → (`image` field → `publicAsset` Volume) and (`portfolioCategories` field → `portfolioCategories` Category Group), confirmed via real two-hop traversal.
3. **`CraftResourceDiscoveryService` regression** - `discoverEntryTypes()`/`getMatrixFields()` (now Registry-backed) still produce the same totals as the direct Craft API.
4. **`WebsiteImportService` Phase 2 regression** - re-ran the exact same Entry #8293 "Portfolio 06" scenario from the original Phase 2 verification; identical `craftSections`/`assetVolumes`/`categoryGroups`/`tagGroups` output confirms the Registry-based rewrite is behavior-preserving.
5. **Navigation, full end-to-end** - `WebsiteImportService::importWebsite()` run against the real `footer` Entry, which already had real, independent menu selections on all four `simpleRpMenu` placements (`support`/`support`/`company`/`categories`); all four captured correctly with real menu items, real resolved `entryRef`s, and real custom URLs. Nothing on the real entry was touched (its value was already set, so the script's would-be-temporary write never fired).
6. **`describeMenu()` directly** against three more real menus (`headerMenu`, `footermenu`, `categories`) and a nonexistent handle (correctly `null`).

## Deferred to later phases

Full settings-backed Platform Configuration (Phase 16's "Group A" registry, a CP settings screen), a real Blueprint Builder consuming `installOrder()` to actually plan an installation (Phase 5), and any install-side application of `manifest->navigation[]` (Phase 6+ - capture only, per this phase's scope).
