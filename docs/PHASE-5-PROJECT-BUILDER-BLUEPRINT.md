# Phase 5 — Project Builder / Dependency Analyzer / Blueprint Builder / Starter Kit Builder

Status: **Implemented, awaiting architectural review before Phase 6.** Per `WEBSITE-STARTER-KIT-SYSTEM.md` Phase 5 - the orchestration layer unifying Phases 1-4's independent capture systems into a single project-building pipeline. **Build-only**: nothing in this phase installs a plugin, runs Composer, runs npm, executes a build tool, or touches a target Craft installation.

## Design decision: compose, don't rewrite

`WebsiteImportService` (Phases 2-4) already does real, verified capture work - pages, globals, `craftSections`/`assetVolumes`/`categoryGroups`/`tagGroups`, Navigation, and Phase 4's whole-environment `dependencies.plugins`/`npmPackages`/`frontendTooling` - and already writes a working package (manifest.json, README.md, `frontend/` config files) to disk. Phase 5 does not reimplement or duplicate any of that. Every new class *composes around* the existing capture, treating `WebsiteImportService::importWebsite()` as a stable, unmodified building block:

```
StarterKitBuilder
  -> ProjectBuilder        (drives WebsiteImportService, assembles a Project)
  -> DependencyAnalyzer    (traverses CraftResourceRegistry, produces an InstallationPlan)
  -> BlueprintBuilder      (turns Project + InstallationPlan into a Blueprint)
  -> writes blueprint.json alongside the existing manifest.json
```

This keeps the responsibility split exactly as specified: Scanner discovers, Registry stores/resolves, Project Builder assembles, Dependency Analyzer calculates, Blueprint Builder generates installation metadata, Starter Kit Builder produces the package - and none of Phases 1-4's already-verified code was touched (aside from two small, additive extensions to `ResourceGraph`/`CraftResourceRegistry`, see below).

## New models

- `src/models/project/Project.php` - the complete assembled representation: `$packageRecord`/`$manifest` (WebsiteImportService's result, unchanged), `$registry` (the same `CraftResourceRegistry` instance WebsiteImportService used while capturing, kept alive for DependencyAnalyzer to traverse without re-scanning Craft), `$platformConfiguration` (new - see below), `$skipped`/`$notes` (passed through). `packagePath()` reconstructs the on-disk path the same way every other package-path consumer in this plugin already does.
- `src/models/project/InstallationPlan.php` - DependencyAnalyzer's output: ordered `$waves` (`plugins` → `schema` → `content` → `frontend`), `$cyclicResources` (resources that couldn't be fully ordered), `$dependencyRelationships` (resolved edge pairs). Every item is a plain, JSON-serializable array (`{type, key, handle, label}`) - never a live Craft object or a `ResourceNode` - since this feeds `BlueprintBuilder`, which must stay independent of the final package format.

## ResourceGraph/CraftResourceRegistry additions (Phase 3 infrastructure, extended)

Two small, backward-compatible additions needed for "detect and report circular dependencies without failing unexpectedly":

- `ResourceGraph::analyzeCycles(): array{ordered, cyclic}` - the same Kahn's-algorithm computation `topologicalOrder()` already did, refactored into a shared private method so a second public method can additionally report **which specific nodes** were left unresolved (participate in a cycle), rather than only knowing "some exist" from `topologicalOrder()`'s silent tail-append. `topologicalOrder()`'s own behavior/contract is unchanged.
- `ResourceGraph::allEdges(): array<{from, to}>` - every edge resolved to node pairs, the raw material for a "dependency relationships" report.

Both mirrored on `CraftResourceRegistry` as pass-throughs (`analyzeCycles()`, `allEdges()`).

## ProjectBuilder

`src/services/ProjectBuilder.php`. `build(entryIds, globalSetIds, meta): Project` calls `WebsiteImportService::importWebsite()` (unmodified) and adds the one piece no existing service computes: a **project-wide Platform Configuration summary**. Phase 3's `PlatformConfigService::categoryFor()` already classifies a single field handle; nothing before this aggregated that across every captured page into `{categories: string[], fields: [{handle, name, category}]}`. Verified live against a real entry with a `titleContainerWidth` field (`spacing` category) and correctly returns empty on a page with no platform-config fields.

## DependencyAnalyzer

`src/services/DependencyAnalyzer.php`. `analyze(Project): InstallationPlan`. The key design point: `CraftResourceRegistry`'s graph covers the **entire** Craft project (every Section/Entry Type/Field, whether or not this package references it) - a Blueprint describing "install this package" must not be a dump of the whole site's schema. So `analyze()`:

1. **Collects seed nodes** - every native Craft resource the manifest itself references by handle (captured pages' Entry Types, `craftSections`, `assetVolumes`, `categoryGroups`, `tagGroups`, `globals`, and Phase 4's captured `dependencies.plugins`).
2. **Computes the closure** - a breadth-first walk of every dependency reachable from those seeds via `dependenciesOf()`.
3. Filters the Registry's full `analyzeCycles()`/`allEdges()` output down to just this closure, so the resulting waves, cyclic-resource report, and dependency relationships all describe *this Project*, not the whole site.
4. Groups the closure into four waves: `plugins` (from the graph's `KIND_PLUGIN` nodes), `schema` (native Craft resource kinds, in the Registry's dependency order), `content` (captured pages/Global Sets - not graph nodes at all, since they're content instances rather than schema; built by the static, Craft-independent `buildContentItems()`), `frontend` (an `npm-install` step if `npmPackages` were captured, a `build` step if `frontendTooling.configFiles` were detected - built by the static `buildFrontendItems()`).

**A real, surprising discovery from live verification, not a hypothesis**: this project's `matrixContent` field (a generic Matrix field allowing dozens of unrelated Entry Types as blocks - the same "flexible page-builder" system Phase 3 traced a cycle through) means the closure of a *single* captured page (`portfolios`) pulls in 251 schema resources, of which 55 are genuinely cyclic (part of the `matrixContainer → matrixRowET → matrixRow → matrixColumn → matrixContent → (any of ~25 entry types) → matrixContainer` cycle Phase 3 first traced). This is real and correct, not a bug: on a project built around one shared, universal content-block system, almost any single page's true dependency closure is nearly the whole project's schema. `analyzeCycles()` still includes every one of those 55 resources exactly once (in insertion-order fallback, per `ResourceGraph`'s documented cycle behavior) - nothing is lost or crashes.

## BlueprintBuilder

`src/services/BlueprintBuilder.php`. `build(Project, InstallationPlan): array` produces the Blueprint document: `resources` (drawn from the manifest's own already-scoped captures, not the Registry - `craftSections`/`assetVolumes`/`categoryGroups`/`tagGroups`/`globalSets`/`pages`/`navigation`), `dependencyRelationships`/`installationOrder` (from the `InstallationPlan`), `requiredPlugins`/`frontendRequirements` (from the manifest's Phase 4 capture), `buildRequirements` (`packageManager`, detected `system`, and npm `scripts` - read from the package's own **copied** `frontend/package.json`, not the original source project, so the Blueprint describes exactly what's inside the `.s7pkg` rather than depending on the source project's continued existence), `platformConfiguration` (from the `Project`), and `validation` (`{valid, errors, warnings}` - errors for missing pages/template references/handle-or-name; warnings for cyclic resources, no frontend tooling detected, or skipped captures).

Deliberately independent of the final package format: nothing here assumes a `.s7pkg`/`manifest.json` shape, so a different package format could consume the same Blueprint unchanged.

## StarterKitBuilder

`src/services/StarterKitBuilder.php`. The single top-level entry point: `build(entryIds, globalSetIds, meta): {project, blueprint}` runs `ProjectBuilder` → `DependencyAnalyzer` → `BlueprintBuilder`, writes `blueprint.json` alongside the existing `manifest.json` inside the package's own directory, and returns both. Strictly build-only, matching this phase's explicit boundary.

## A naming collision, caught only by live testing (again)

Two more `craft\base\Component`/`yii\base\Model` method-name collisions surfaced only when actually running against a bootstrapped Craft app, not from `php -l` or unit tests:

- `CraftResourceRegistry`-adjacent work already renamed `load()` → `getGraph()` in Phase 3 for the same reason.
- `BlueprintBuilder::validate()` collided with `yii\base\Model::validate()` (public; a private override is a fatal visibility-narrowing error) - renamed to `validatePackage()`.

Every service in this plugin extends `craft\base\Component`, which descends from `yii\base\Model` - a real, growing list of reserved names (`load`, `validate`, `fields`, `attributes`, `behaviors`, `scenarios`, ...) that a `php -l` pass or a Craft-independent unit test can't catch, since the collision only manifests once the class hierarchy is actually resolved by a live PHP runtime with the full autoloader. Worth checking this list before naming a new public/protected method on any class in this codebase.

## Tests

- `tests/unit/models/registry/ResourceGraphTest.php` (extended) - `analyzeCycles()` reports exactly the unresolved/cyclic nodes on both a cyclic and an acyclic graph; `allEdges()` resolves edges to node pairs and excludes edges to unknown nodes.
- `tests/unit/services/DependencyAnalyzerTest.php` (new) - `buildContentItems()`/`buildFrontendItems()` (made `public static` specifically for this, since they're pure array-shaping with no graph traversal) against a hand-built `PackageManifest`: pages/globals described correctly, empty manifest yields empty arrays, the `build` wave item is included only when `frontendTooling.configFiles` is non-empty (an npm-only project with no detected build config gets `npm-install` but no `build` step).

The graph-traversal half of `DependencyAnalyzer::analyze()` (closure computation, cycle-aware wave assignment) needs a live `CraftResourceRegistry`/Craft app and has no practical Craft-independent unit test - covered by live DDEV verification instead, matching this repo's established convention. This host still has no PHPUnit/Codeception binary, so every assertion above was additionally hand-verified via a direct PHP script.

## Live verification

All against the real DDEV site, every temporary package deleted immediately after (confirmed via `git status` on `packages/` showing no residue):

1. **Determinism across repeated builds** - `StarterKitBuilder::build()` run twice with **identical** input (entry #8293 "Portfolio 06", identical meta), with the first run's packages deleted before the second run started (otherwise identical input would legitimately produce different auto-uniquified handles, which isn't a determinism failure - it's the handle-collision guard working correctly). The two Blueprints were structurally identical (`packageHandle`/`packageName`/`resources`/`installationOrder` - excluding only `generatedAt`... actually the Blueprint carries no such volatile field at all in the current schema, so no exclusion was even needed in practice).
2. **Blueprint accuracy** - `resources.craftSections` correctly scoped to just `portfolios` (not the whole project's 35 Sections); `assetVolumes`/`categoryGroups` matched the real Phase 2 findings (`publicAsset`, `blogCategories` + `portfolioCategories`); `requiredPlugins` carried the full whole-project plugin list (22, per Phase 4's project-wide-by-design scope); `validation.valid` was `true` with no errors.
3. **Dependency ordering** - the one pair already proven genuinely acyclic (Phase 3: `publicAsset` Volume → the `image` field that depends on it) still ordered correctly within the schema wave.
4. **Cycle reporting** - confirmed real and substantial (55 cyclic resources out of 251 in the schema wave, a `circular dependency` validation warning present) rather than assumed; every cyclic resource still appeared exactly once.
5. **Physical `blueprint.json`** - written to the package directory, valid JSON, matching the in-memory Blueprint exactly.
6. **Platform Configuration** - verified against a real entry (`#12016 "Components Demo"`) with a genuine `titleContainerWidth` field, correctly categorized as `spacing`; verified empty (not erroring) on a page with no platform-config fields.

## Deferred to later phases

Actual installation of anything in the Blueprint (plugin install, `composer install`, `npm install`, running the detected build, applying captured schema/content to a target site) - Phase 6+. A "capture everything" one-click flow above today's manual multi-select (mentioned in the master plan's Phase 5 description) was not built in this pass - `ProjectBuilder`/`StarterKitBuilder` still take the same `entryIds`/`globalSetIds` selection `WebsiteImportService` always has; a whole-project capture mode is a natural, low-risk extension of `ProjectBuilder` (querying every top-level Entry via Craft's own APIs) but was left out to keep this phase's diff focused on the orchestration layer itself, per the review-before-Phase-6 checkpoint this phase ends on.
