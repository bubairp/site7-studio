# 25 — Dependencies and Shared Resources

## 1. Purpose

Document how a package declares dependencies on other packages or on shared Craft resources (Fields, Volumes, etc.), and how "Shared Resource" resolution deliberately never blocks install.

## 2. What It Does

`site7_package_dependencies` records what a package needs; `site7_shared_resources`/`site7_shared_resource_dependencies` track registered shared Craft resources and their own dependency graph; `DependencyResolverService` and `DependencyAnalyzer` compute resolution/closures for two different consumers (install-time warnings vs. Starter Kit build-time waves).

## 3. Current Status

**Implemented.**

## 4. Architecture

```
manifest.requires (per package) + manifest.dependencies.sharedResources
   ↓  MarketplaceService::syncDependencyRecords($record)
   ↓  (deletes existing rows, re-writes from manifest — this is the ONLY place
   ↓   "Referenced By"/usage count for a Shared Resource is computed from)
site7_package_dependencies  (dependencyType, dependencyHandle, minimumVersion, optional)

site7_shared_resources (handle, type, craftUid/craftId, installStatus, definitionSnapshot)
site7_shared_resource_dependencies (sharedResourceId → dependsOnHandle)
   ↑
SharedResourceRegistryService / SharedResourceUsageService

Install-time:  PackageManagerService::installPackage()
   ↓  DependencyResolverService::resolveSharedResources($handles)
   ↓  BFS over Shared→Shared edges via sharedResourceRegistry
   ↓  {sharedResources: [{handle, status: 'linked'|'missing'}], warnings: []}
   ↓  NEVER BLOCKS — warnings collected, install proceeds regardless

Starter-Kit-build-time:  DependencyAnalyzer::analyze() (called by StarterKitBuilder)
   ↓  internally uses private computeClosure() — BFS over the whole-project CraftResourceRegistry graph,
   ↓  narrowed to what THIS manifest needs
   ↓  produces waves: plugins / schema / content / frontend, plus
   ↓  cyclicResources / dependencyRelationships
```

## 5. Execution Flow

1. `MarketplaceService::syncDependencyRecords(PackageRecord $record)` — called after import/authoring saves a manifest; deletes all existing `site7_package_dependencies` rows for the package, re-writes from `manifest->requires` (one row per declared dependency, `dependencyType` per entry) plus `manifest->dependencies['sharedResources']` entries with `dependencyType = 'sharedResource'`.
2. At install time, `PackageManagerService::installPackage()` collects the package's declared shared-resource handles and calls `DependencyResolverService::resolveSharedResources($sharedResourceHandles)`.
3. `resolveSharedResources()` — BFS walk over Shared→Shared edges registered in the shared-resource registry; for each requested handle, calls `isLive()` (re-checks liveness per Craft resource type: field/matrix/entryType/volume/categoryGroup/tagGroup/globalSet) and classifies `status: 'linked'` or `'missing'`.
4. Any `'missing'` result becomes a warning string, NOT an exception — collected into `$_lastInstallWarnings`, logged via `Craft::warning()`, surfaced to the CP via `getLastInstallWarnings()` as a post-install notice. **Install completes regardless.**
5. Separately, `DependencyAnalyzer::analyze(Project $project): InstallationPlan` — the public entry point actually called by `StarterKitBuilder::build()` — narrows the whole-project `CraftResourceRegistry` graph to exactly what a specific manifest needs, producing ordered installation waves (`32_STARTER_KIT_SYSTEM.md`). `computeClosure()` is a **private** helper `analyze()` calls internally, not a method `StarterKitBuilder` invokes directly.

## 6. Important Classes

**`MarketplaceService::syncDependencyRecords()`** — `src/services/MarketplaceService.php`.
**`DependencyResolverService`** — `src/services/DependencyResolverService.php`. Methods: `resolveSharedResources()`, `isLive()`.
**`DependencyAnalyzer`** — `src/services/DependencyAnalyzer.php`. Public entry point: `analyze(Project $project): InstallationPlan`, called by `StarterKitBuilder::build()`. `computeClosure()` is a private helper `analyze()` uses internally. Static helpers `buildContentItems()`/`buildFrontendItems()` (pure, unit-testable without a live Craft app — see `DependencyAnalyzerTest`).
**`SharedResourceRegistryService`** — `src/services/SharedResourceRegistryService.php`.
**`SharedResourceUsageService`** — `src/services/SharedResourceUsageService.php`.
**`SharedResourceController`** — `src/controllers/SharedResourceController.php`. Actions: `actionIndex`, `actionPreview`, `actionImport`, `actionExport`, `actionUpdate`, `actionDelete`.
**Records**: `PackageDependencyRecord` (`src/records/PackageDependencyRecord.php`), `SharedResourceRecord`, `SharedResourceDependencyRecord` (`src/records/`).

## 7. Data Model

**`site7_package_dependencies`**: `id`, `packageId` (FK→`site7_packages` CASCADE), `dependencyType`, `dependencyHandle`, `minimumVersion`, `optional` (bool, default false), `dateCreated`, `dateUpdated`.

**`site7_shared_resources`**: `id`, `uid`, `handle` (unique index), `name`, `type` (indexed), `craftUid`, `craftId`, `version` (default `1.0.0`), `installStatus` (default `registered`), `definitionSnapshot`, `dateCreated`, `dateUpdated`.

**`site7_shared_resource_dependencies`**: `id`, `sharedResourceId` (FK→`site7_shared_resources` CASCADE), `dependsOnHandle`, `dependencyType`, `dateCreated`, `dateUpdated`.

## 8. Filesystem Impact

None — this entire subsystem is database/in-memory graph only.

## 9. Events

None dispatched directly by these services.

## 10. Validation and Safety

**"Never blocks install" is the central safety rule of this document**: confirmed directly in `PackageManagerService::installPackage()` — a missing Shared Resource is warned about, never a hard failure, leaving the developer to resolve it manually from the Shared Resources Library (Import/Create/Skip). This is a deliberate design choice, restated verbatim from the code comment: "a missing Shared Resource is warned about and left for the developer to resolve... see `DependencyResolverService`'s docblock."

**`syncDependencyRecords()` is delete-then-rewrite, not incremental diff** — the entire dependency-row set for a package is always fully replaced from the current manifest state, never patched — simpler and avoids stale rows accumulating.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Required Shared Resource not present on target site | Install proceeds; warning surfaced in CP |
| Circular Shared→Shared dependency | Handled by BFS with visited-tracking (implied by "BFS over Shared→Shared edges" — no infinite loop) |
| `DependencyAnalyzer::computeClosure()` finds a cyclic dependency at Starter Kit build time | Reported in `cyclicResources`, not silently dropped |

## 12. Developer Change Guide

If you need install-time dependency checking for a new resource type: extend `DependencyResolverService::isLive()`'s per-type switch — do not add a second resolver.
If you need Starter-Kit-build-time dependency ordering: extend `DependencyAnalyzer`, which is a structurally separate concern (whole-project closures/waves, not single-package linked/missing checks).

## 13. Related Features

`06_PACKAGE_ARCHITECTURE.md`, `11_PACKAGE_INSTALLATION.md`, `32_STARTER_KIT_SYSTEM.md`.

## 14. Known Limitations

`site7_package_dependencies` was noted in code comments as "never populated until Package Distribution" historically — now populated via `syncDependencyRecords()`, but no confirmed UI surfaces the full dependency graph directly (only Shared Resource "Referenced By" counts, per §4).
