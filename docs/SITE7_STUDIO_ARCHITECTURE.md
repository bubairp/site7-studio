# SITE7 Studio — Master Technical Architecture

**Status: MASTER DOCUMENT — the authoritative, living source of truth for this plugin's architecture.**

This is not a one-time audit snapshot. When a future change alters how a subsystem works, **update the relevant section of this document in the same change** rather than writing a new, competing architecture document. If a specialized document already covers part of a subsystem in more depth (see [Related Documents](#related-documents)), this document summarizes it and links out — it does not duplicate it.

Last verified against source: 2026-08-17, immediately after Step 8.2 (commit `438ce75`, branch `cleanup/dead-templates-checkpoint-20260817`).

---

## Table of Contents

1. [Document Purpose](#1-document-purpose)
2. [Product Overview](#2-product-overview)
3. [Core Architecture](#3-core-architecture)
4. [Directory / Codebase Map](#4-directory--codebase-map)
5. [Bootstrap and Plugin Lifecycle](#5-bootstrap-and-plugin-lifecycle)
6. [Data Model / Database Architecture](#6-data-model--database-architecture)
7. [Package Model](#7-package-model)
8. [Package Manifest](#8-package-manifest)
9. [Package Authoring](#9-package-authoring)
10. [Package Build / Export](#10-package-build--export)
11. [Package Import](#11-package-import)
12. [Import Existing Section](#12-import-existing-section)
13. [Import Existing Page](#13-import-existing-page)
14. [Import Existing Website](#14-import-existing-website)
15. [Starter Kit / Native Craft Resource System](#15-starter-kit--native-craft-resource-system)
16. [Craft Resource Installation](#16-craft-resource-installation)
17. [Template File Lifecycle](#17-template-file-lifecycle)
18. [Owned Files Architecture](#18-owned-files-architecture)
19. [Frontend Tooling](#19-frontend-tooling)
20. [Sync From Source](#20-sync-from-source)
21. [Version Management](#21-version-management)
22. [Version History / Archives](#22-version-history--archives)
23. [Installed File Baseline](#23-installed-file-baseline)
24. [Update / Three-Way Conflict System](#24-update--three-way-conflict-system)
25. [Rollback](#25-rollback)
26. [Uninstall / Delete](#26-uninstall--delete)
27. [Backup / Local Repository](#27-backup--local-repository)
28. [Dependencies](#28-dependencies)
29. [Marketplace / Commerce / Licensing](#29-marketplace--commerce--licensing)
30. [Package Publishing](#30-package-publishing)
31. [Controllers / CP UI](#31-controllers--cp-ui)
32. [Events / Subscribers](#32-events--subscribers)
33. [Services Map](#33-services-map)
34. [Error Handling / Validation](#34-error-handling--validation)
35. [Testing Architecture](#35-testing-architecture)
36. [Security / Safety Model](#36-security--safety-model)
37. [Complete Package Lifecycle](#37-complete-package-lifecycle)
38. [Architecture Invariants — DO NOT BREAK THESE](#38-architecture-invariants--do-not-break-these)
39. [Common Bug / Issue-Fixing Guide](#39-common-bug--issue-fixing-guide)
40. [How to Safely Modify Site7 Studio](#40-how-to-safely-modify-site7-studio)
41. [Extension Guide for New Features](#41-extension-guide-for-new-features)
42. [Future Product Website / Public Documentation](#42-future-product-website--public-documentation)
43. [Current Limitations / Deferred Work](#43-current-limitations--deferred-work)
44. [Appendix — Class / Service Index](#44-appendix--class--service-index)
45. [Appendix — Database Table Index](#45-appendix--database-table-index)
46. [Appendix — File / Directory Ownership](#46-appendix--file--directory-ownership)

---

## Related Documents

This document is the **master index and cross-subsystem reference**. For exhaustive detail on a single subsystem, read the specialized document instead of expecting this one to repeat it in full:

| Document | Location | Covers |
|---|---|---|
| `21_TEMPLATE_ARCHITECTURE.md` | `docs/` (repo root, host-site docs) | The RP Craft **host site's** own `templates/` folder restructuring (the `_blocks/`/`_components/`/`_macros/` rename project) — not a Site7 Studio subsystem, but the ground truth for what `templates/_blocks/` actually is. |
| `22_TEMPLATES_GUIDE.md` | `docs/` (repo root) | Companion guide to the above. |
| `23_SITE7_STUDIO_PACKAGE_TEMPLATE_LIFECYCLE.md` | `plugins/site7-studio/docs/` | The permanent architecture rules for the package/template lifecycle (Steps 2–7 era) — the core invariants restated in §38 here originate from that document. |
| `WEBSITE-STARTER-KIT-SYSTEM.md` + `PHASE-1` … `PHASE-8-*.md` | `plugins/site7-studio/docs/` | The whole-site capture/install/sync pipeline (§15–16 here summarize; read these for full phase-by-phase design rationale, including the documented process-boundary bugs). |
| `VALIDATION-REPORT-FULL-PIPELINE.md` | `plugins/site7-studio/docs/` | Ground-truth, real-data validation of the Starter Kit pipeline — the authoritative list of what's actually production-ready vs. not (§43 here summarizes). |
| `PACKAGE-SAFETY-AND-BACKUP.md` | `plugins/site7-studio/docs/` | Deletion safety, usage checks, backup/local-repository detail (§26–27 here summarize). |
| `PHASE-16-FEATURE-PACKAGE-ARCHITECTURE.md` | `plugins/site7-studio/docs/` | Shared Resource Registry design rationale (§28 here summarizes). |
| `PHASE-24-COMMERCE-LICENSING.md` | `plugins/site7-studio/docs/` | Commerce24/licensing design rationale (§29 here summarizes). |

**Known discrepancy, resolved in favor of current code**: `21_TEMPLATE_ARCHITECTURE.md` (written 2026-08-03) still describes `templates/site7-components/` as "UNCHANGED — plugin-managed, auto-generated, do not touch." This is now stale. `CraftResourceService::generateResources()` installs into `templates/_blocks/{handle}.twig` directly; `templates/site7-components/` is confirmed dead (contains one leftover file, `clientLogos.twig`, predating this architecture, never written to or read from by any current code path). §17 of this document describes the current, correct behavior.

---

## 1. Document Purpose

**Why this document exists**: Site7 Studio has grown, across many incremental phases, into a plugin with a genuinely complex package lifecycle (create → import → version → sync → install → update → rollback → uninstall) plus an adjacent whole-site Starter Kit system and a Commerce/Marketplace layer. No single place previously explained how these pieces fit together as one system. This document is that place.

**Who should use it**:
- A new developer joining this codebase, before touching anything.
- An AI coding agent, before modifying any subsystem — read the relevant section first.
- Anyone diagnosing a bug, to find the actual code path instead of re-deriving it from scratch.
- Anyone planning a new feature, to see which existing services to reuse and which architectural rule not to violate.

**What it covers**: the plugin's own architecture — services, models, records, migrations, controllers, events, the package lifecycle, the Starter Kit system, Commerce/Marketplace.

**What it does not cover**: the RP Craft **host site's** own template/frontend design conventions (Tailwind usage, component CSS organization, page-level Twig patterns) — that's `docs/00_PROJECT_OVERVIEW.md` through `docs/22_TEMPLATES_GUIDE.md` at the repo root, a separate documentation set for a separate audience (site theme developers, not plugin developers).

> **Rule: read the relevant architecture section before modifying that subsystem.** Sections 38–41 exist specifically to make this fast — invariants, a troubleshooting index, a safe-modification workflow, and an extension guide.

---

## 2. Product Overview

Site7 Studio is a Craft CMS plugin (`site7\studio`, Composer package `site7/studio`) that turns pieces of a Craft site — a Section (a Matrix block type), a Page, or a whole Website — into **packages**: versioned, exportable, re-installable units that can be moved between Craft sites, updated safely without destroying local customizations, and rolled back to any prior version.

**The problem it solves**: Craft CMS has no native concept of "take this component I built on Site A and safely reuse/update it on Site B" beyond raw Project Config YAML, which has no versioning, no update-conflict detection, and no rollback. Site7 Studio adds that layer on top of Craft's own APIs, without replacing them.

**Who uses it**: developers building/maintaining Craft sites who want to reuse components across projects (agencies with a component library), and — via the Commerce/Marketplace layer — potentially a broader marketplace of installable packages with licensing.

**What a package is**: a directory (`packages/{handle}/`) containing a `manifest.json` plus type-specific content (fields, Matrix configuration, a real Twig template, optionally owned frontend files, preview assets). A package has a `type` (`section`, `template`, `pattern`, `starter-kit`, `theme`), a `handle` (kebab-case, globally unique), and a semantic `version`.

**Relationship to Craft CMS**: Site7 Studio is a normal Craft plugin — it uses Craft's own APIs (Fields service, Entries service, Project Config) to create/update the actual Craft resources a package needs (Fields, Entry Types). It never writes `config/project/*.yaml` directly except via `Craft::$app->getProjectConfig()->rebuild()`.

**Relationship to the host website**: a package's real, live-rendered output lives in the host site's own `templates/_blocks/{handle}.twig` — Site7 Studio installs *into* the site's existing rendering system (§17), it does not run a second one.

**Relationship to Marketplace/Commerce**: entirely additive and separable. The core package engine (`PackageManagerService`, `VersionManagerService`, etc.) works with zero Commerce configuration — a `LocalMarketplaceRepository` (a folder on disk) is always available. Commerce24 (§29) is an optional remote marketplace/licensing integration that gates *additional* packages behind entitlements; it never gates the core lifecycle.

**High-level lifecycle** (detailed end-to-end in §37):
```
Create/Author or Import  →  Version (immutable, archived)  →  Publish (optional)
        →  Install  →  Baseline recorded  →  Develop further  →  Sync (new version)
        →  Update (safe/conflict)  →  Rollback (if needed)  →  Uninstall/Delete
```

---

## 3. Core Architecture

```
Craft CMS (Fields/Entries/Project Config/Elements APIs)
   ↓
Site7 Studio plugin (Site7Studio extends craft\base\Plugin)
   ↓
Service Providers (register ~70 named components on the plugin's Yii service locator)
   ↓
┌─────────────────────────────────────────────────────────────────────┐
│ Domain layer                                                        │
│  ├─ Package Engine        (PackageManagerService, PackageReader,    │
│  │                          PackageDiscovery, records/*)             │
│  ├─ Import services        (MatrixEntryTypeImportService,           │
│  │                          CraftSectionImportService,               │
│  │                          PageImportService, WebsiteImportService) │
│  ├─ Sync services          (SectionUpdateService, PageUpdateService, │
│  │                          StarterKitSyncService)                   │
│  ├─ Version/Archive layer  (VersionManagerService,                  │
│  │                          PackageExportService, PackageImportService,│
│  │                          PackageArchiveHelper, MarketplaceService) │
│  ├─ Update/Conflict layer  (PackageUpdatePlanner,                    │
│  │                          InstalledFileBaselineService)            │
│  ├─ Rollback layer         (PackageRollbackService)                 │
│  ├─ Craft resource layer   (CraftResourceService, CraftResourceRegistry,│
│  │                          CraftResourceScanner)                    │
│  ├─ Starter Kit system     (ProjectBuilder, DependencyAnalyzer,      │
│  │                          BlueprintBuilder, installation/*,        │
│  │                          synchronization/*)                       │
│  ├─ Dependency layer       (DependencyResolverService,               │
│  │                          SharedResourceRegistryService)           │
│  ├─ Publishing layer       (PackageBuilderService, PublishValidatorService,│
│  │                          RepositoryManagerService, PackagePublisherService)│
│  └─ Commerce layer         (CommerceClient, LicenseService,          │
│                             SubscriptionService, PlanService,        │
│                             commerce\PackageService, FeatureGateService)│
└─────────────────────────────────────────────────────────────────────┘
   ↓
Storage / Archives (packages/{handle}/ on disk, storage/site7-studio/{exports,marketplace-repo,commerce24-cache,runtime}/, .s7pkg zip archives)
   ↓
Host site resources/files (templates/_blocks/*.twig, Craft Fields/Entry Types/Sections/Volumes/Category & Tag Groups, frontend/ owned files, config/project/*.yaml via rebuild())
```

**Layer inventory, mapped to actual directories** (no invented layers — every bullet below is a real `src/` subdirectory):
- **Plugin bootstrap**: `src/Site7Studio.php`, `src/base/PluginTrait.php`.
- **Providers** (`src/providers/*.php`): `CoreServiceProvider`, `EventServiceProvider`, `CpServiceProvider`, `LibraryServiceProvider`, `ImportServiceProvider`, `CommerceServiceProvider`, `PublishingServiceProvider` — each implements `ServiceProviderInterface::register(Site7Studio $plugin)`.
- **Services** (`src/services/**/*.php`) — the bulk of the domain logic; subdivided into `services/`, `services/import/`, `services/publishing/`, `services/synchronization/`, `services/installation/`, `services/installation/executors/`, `services/support/`, `services/commerce/`, `services/scanning/`, `services/engine/`.
- **Models** (`src/models/**/*.php`) — plain `craft\base\Model` DTOs: `models/packages/*` (Package subclasses + `PackageManifest`), `models/commerce/*`, `models/marketplace/*`, `models/installation/*`, `models/synchronization/*`, `models/project/*`, `models/registry/*`, `models/publishing/*`.
- **Records** (`src/records/*.php`) — `craft\db\ActiveRecord` subclasses, one per database table (§6).
- **Migrations** (`src/migrations/*.php`) — 15 timestamped migrations + `Install.php` (§6).
- **Controllers** (`src/controllers/*.php`, `src/console/controllers/*.php`) — CP web controllers and console commands (§31).
- **Events** (`src/events/**/*.php`) — event classes + `src/events/subscribers/*.php` (§32).
- **Repositories** (`src/repositories/**/*.php`) — thin, table-scoped read/write helpers distinct from full services (§6).
- **Support** (`src/services/support/*.php`) — stateless helper classes: `PackageArchiveHelper`, `PackageBackupService`.
- **Interfaces** (`src/interfaces/*.php`) — contracts for pluggable pieces (marketplace repositories, publish targets, commerce client, license/subscription/package providers, version manager, package builder/publisher/validator/signer).
- **Executors** (`src/services/installation/executors/*.php`) — one class per installation step type, implementing `StepExecutorInterface`.
- **Scanners** (`src/services/scanning/*.php`, plus `FrontendToolingScanner`, `CraftResourceScanner`, `ComposerDependencyScanner`) — read-only detection of live Craft/filesystem state.
- **Validators** (`ResourceImportValidator`, `InstallationValidator`, `SynchronizationValidator`, `PublishValidatorService`) — each scoped to one pipeline stage, never shared.
- **Planners** (`InstallationPlanner`, `SynchronizationPlanner`, `PackageUpdatePlanner`) — each strictly read-only, separate from the executor that acts on their output.

---

## 4. Directory / Codebase Map

```
plugins/site7-studio/
├── src/
│   ├── Site7Studio.php              # Plugin entry class - bootstrap, routes, isDevMode()
│   ├── base/PluginTrait.php         # @property-read component accessors mixin
│   ├── config.php                    # Plugin config defaults
│   ├── providers/                    # ServiceProviderInterface implementations (7 files)
│   ├── controllers/                  # CP web controllers (15 files) - see §31
│   ├── console/controllers/          # Console commands (5 files) - see §31
│   ├── services/                     # Domain services - the largest directory
│   │   ├── import/                   # Import Existing X + Sync From Source services
│   │   ├── publishing/               # Build/export/publish/version pipeline
│   │   ├── synchronization/          # Starter Kit sync engine + PackageUpdatePlanner/InstalledFileBaselineService
│   │   ├── installation/             # Starter Kit installation pipeline
│   │   │   └── executors/            # One class per install step type
│   │   ├── support/                  # PackageArchiveHelper, PackageBackupService - stateless helpers
│   │   ├── commerce/                 # Commerce24 client + business services
│   │   ├── scanning/                 # Read-only Craft-state scanners (native resources)
│   │   └── engine/                   # PackageReader, PackageDiscovery
│   ├── models/                       # Plain Model DTOs - never DB-backed, never contain business logic
│   ├── records/                      # ActiveRecord subclasses - one per table, mostly bare
│   ├── repositories/                 # Thin table-scoped read/write helpers (not full services)
│   │   └── marketplace/              # File/HTTP-backed "repositories" (not DB tables)
│   ├── migrations/                   # 15 timestamped migrations + Install.php
│   ├── events/                       # Event classes
│   │   └── subscribers/              # CpSubscriber, PackageBackupSubscriber
│   ├── interfaces/                   # Contracts for pluggable subsystems
│   ├── widgets/                      # LibraryWidget (Craft Dashboard widget)
│   ├── assetbundles/                 # CP JS/CSS asset bundles
│   ├── log/                          # Site7FileTarget (custom log target)
│   └── translations/                 # en/site7-studio.php
├── docs/                             # Plugin's own documentation (this file lives here)
├── tests/                            # Codeception/PHPUnit test suite - see §35
├── packages/                         # Package SOURCE directory (the plugin's own managed storage)
├── codeception.yml, phpunit.xml.dist # Test runner config (see §35 for setup history)
└── composer.json
```

**Per-directory rules** (what belongs / does not belong):

| Directory | Belongs there | Does NOT belong there |
|---|---|---|
| `services/` (root) | Cross-cutting domain services used by many callers (`PackageManagerService`, `CraftResourceService`, `DependencyResolverService`) | Anything scoped to one import source or one pipeline stage — those go in a subdirectory |
| `services/import/` | Import Existing X services, Sync From Source, resource classification/discovery/validation | Craft-resource *installation* logic (that's `CraftResourceService`, not here) |
| `services/publishing/` | Build/validate/publish/version-history services, `PackageRollbackService` | Marketplace repository implementations (those are `repositories/marketplace/`) |
| `services/synchronization/` | Both the Starter-Kit-level `SynchronizationPlanner` AND the newer file-level `PackageUpdatePlanner`/`InstalledFileBaselineService` — two related but distinct mechanisms sharing one directory by convention, not by code sharing | A new third "conflict system" — reuse one of the two existing ones (§38) |
| `services/support/` | Genuinely stateless, dependency-free helpers (`PackageArchiveHelper` is pure static methods) | Anything with its own state/DB access |
| `models/packages/` | `Package`/`PackageManifest`/type subclasses — data only | Any method that writes to disk or the DB (models are never persistence-aware) |
| `records/` | One bare (or near-bare) `ActiveRecord` per table | Business logic — records are intentionally thin; logic lives in the owning service |
| `repositories/` | Table-scoped CRUD for **source-tracking** tables specifically (`site7_section_import_sources` etc.) | General package CRUD — that's `PackageManagerService`/`PackageRepository`, not here |
| `interfaces/` | A contract with more than one real or plausible implementation | A contract for something that will only ever have one implementation (that's over-abstraction — see §38) |

---

## 5. Bootstrap and Plugin Lifecycle

**Plugin class**: `src/Site7Studio.php`, `class Site7Studio extends craft\base\Plugin`, `use PluginTrait;` (mixes in `@property-read` component accessors, e.g. `$plugin->packageManager`).

**What happens when Craft starts** (in order):
1. Craft loads `Install.php` + every timestamped migration if this is a fresh install/upgrade (§6).
2. `Site7Studio::init()` runs: calls `registerServiceProviders()`, which instantiates and calls `->register($this)` on, in order: `CoreServiceProvider`, `EventServiceProvider`, `CpServiceProvider`, `LibraryServiceProvider`, `ImportServiceProvider`, `CommerceServiceProvider`, `PublishingServiceProvider`. Each provider calls `$plugin->set('name', [...])` for its own services — after this, every named component listed in §33 is reachable as `Site7Studio::getInstance()->{name}`.
3. `attachEventHandlers()` (invoked from `Craft::$app->onInit()`, not directly in `init()`, so it runs after Craft itself is fully booted) does three things:
   - `yii\base\Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, ...)` — registers the plugin's explicit CP GET routes (the full list is in §31).
   - Registers `PatternMatrixBundle` and injects `window.site7Studio = {matrixFieldHandle}` on every CP page (`View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE`).
   - Adds a "Save as Template" alt-action to the Entry edit screen's Save dropdown (`Entry::EVENT_DEFINE_ALT_ACTIONS`).
4. `PackageBackupSubscriber` and `CpSubscriber` are registered against `eventDispatcher` inside `ImportServiceProvider`/`CpServiceProvider` respectively — see §32 for what they do.
5. `CpNavigationRegistry::getNavItems()`/`CpPermissionRegistry::getPermissions()` are lazily populated the first time the CP reads them (each fires its own `RegisterNavigationEvent`/`RegisterPermissionsEvent` once, memoized) — this is what actually causes `CpSubscriber`'s handlers to run.

**Console commands**: `src/console/controllers/*.php` — see §31 for the full list. Reachable as `php craft site7-studio/<controller-id>/<action-id>`.

**`Site7Studio::isDevMode()`**: `static` wrapper around `Craft::$app->getConfig()->getGeneral()->devMode` — the single gate used by every "Dev Mode only" controller action (§31).

---

## 6. Data Model / Database Architecture

All 15 `site7_*` tables (Craft's `{{%...}}` prefix applies to all). Confirmed against every migration file directly — this is the complete, current schema. See §45 for the flat index; this section explains relationships and lifecycle.

```
site7_packages (1) ──┬──< site7_components            (Section/Component metadata)
                      ├──< site7_templates              (Template package metadata)
                      ├──< site7_package_dependencies    (forward edges: package→package, package→sharedResource)
                      ├──< site7_package_versions        (immutable version history, §21-22)
                      ├──< site7_package_publications     (publish-attempt history, §30)
                      ├──< site7_section_import_sources   (1:1 - Import Existing Section provenance, §12)
                      ├──< site7_page_import_sources      (1:1 - Import Existing Page provenance, §13)
                      ├──< site7_website_import_sources   (1:1 - Import Existing Website provenance, §14)
                      └──< site7_installed_files          (installed-file baseline, §23)

site7_shared_resources (1) ──< site7_shared_resource_dependencies  (Shared→Shared forward edges)

site7_installed_starter_kits   (standalone - whole-Blueprint baseline snapshot per installed Starter Kit)
site7_sync_history             (standalone - one row per Starter Kit sync attempt)
site7_sync_sessions            (standalone - cross-process sync session state)
site7_install_sessions         (standalone - cross-process install session state)
```

All foreign keys to `site7_packages.id` use **ON DELETE CASCADE, ON UPDATE CASCADE** — deleting a `PackageRecord` automatically removes every version, dependency, source-tracking, and installed-file row for it, with zero application-level cleanup code required (§26 relies on this directly).

| Table | Purpose | Key columns | Unique/FK | Cascade | Owner service (writes) | Readers |
|---|---|---|---|---|---|---|
| `site7_packages` | The package registry — one row per discovered/installed package | `handle`, `type`, `version`, `status` (install lifecycle: available/installed/enabled/disabled), `authoringStatus` (draft/preview/testing/published/deprecated/archived), `category`, `tags`, `creatorId`, `entitlementRemovableOn` | `handle` unique; `creatorId` FK→`users.id` ON DELETE SET NULL | — | `PackageManagerService`, `PackageAuthoringService`, `PackageRepository` | almost everything |
| `site7_components` | Section-package-specific CP metadata (icon, preview image, Matrix Entry Type handle) | `packageId`, `matrixEntryTypeHandle`, `enabled` | FK→packages CASCADE | on package delete | `PackageManagerService` (discovery) | `LibraryController` |
| `site7_templates` | Template-package-specific CP metadata | `packageId`, `templateCategory`, `homepage` | FK→packages CASCADE | on package delete | `PackageManagerService` (discovery) | `LibraryController` |
| `site7_package_dependencies` | Forward-only edges: package→package (`requires`) and package→sharedResource | `packageId`, `dependencyType`, `dependencyHandle` | FK→packages CASCADE | on package delete | `MarketplaceService::syncDependencyRecords()` | `SharedResourceRegistryService::getDependentPackages()`, dependency-closure resolution (§10, §28) |
| `site7_package_versions` | **Immutable** version history — one row per recorded version, with real archive+checksum | `packageId`, `version`, `checksum`, `archivePath`, `releaseNotes`, `releaseDate` | FK→packages CASCADE | on package delete | `MarketplaceService::recordVersion()` (sole writer, dedup-safe on `packageId+version`) | `VersionManagerService`, `PackageUpdatePlanner`, `PackageRollbackService`, `LibraryController` |
| `site7_package_publications` | One row per publish *attempt* (a version can publish to >1 target) | `packageId`, `repositoryHandle`, `version`, `status`, `signature` (reserved, unused) | FK→packages CASCADE | on package delete | `PublishHistoryService::recordPublish()` | `PackagePublisherController` |
| `site7_section_import_sources` | Import Existing Section provenance + update-detection hash | `packageId` (unique), `sourceUid` (unique), `sourceHash` | FK→packages CASCADE; both cols unique (1:1) | on package delete | `SectionImportSourceRepository::record()` | `SectionUpdateService::resolve()`, `PackageAuthoringService` (locked-field check) |
| `site7_page_import_sources` | Import Existing Page provenance + update-detection hash | `packageId` (unique), `sourceUid` (unique), `sourceHash` | same shape as above | on package delete | `PageImportSourceRepository::record()` | `PageUpdateService` |
| `site7_website_import_sources` | Import Existing Website provenance — keyed by a computed `selectionKey` (sha256 of sorted entry uids, since a website has no single source uid) | `packageId` (unique), `selectionKey` (unique), `sourceEntryUids` | same shape | on package delete | `WebsiteImportSourceRepository::record()` | `StarterKitSyncService`, `PackageAuthoringController` |
| `site7_installed_files` | **Installed-file baseline** — checksum of a file exactly as this package last wrote it | `packageId`, `resourceHandle`, `targetPath`, `installedVersion`, `checksum` | composite `(packageId, targetPath)` unique; FK→packages CASCADE | on package delete | `InstalledFileBaselineService` (sole writer) | `PackageUpdatePlanner`, `PackageManagerService::updateInstalledFiles()`, `PackageRollbackService` |
| `site7_shared_resources` | Registry of Craft resources intentionally reused across many packages | `handle` (unique), `type`, `craftUid`, `craftId`, `installStatus` | `handle` unique | — | `SharedResourceRegistryService` | `DependencyResolverService`, `ResourceClassifierService` |
| `site7_shared_resource_dependencies` | Shared→Shared forward edges | `sharedResourceId`, `dependsOnHandle` | FK→shared_resources CASCADE | on shared resource delete | `SharedResourceRegistryService::syncSharedDependencies()` | `DependencyResolverService` |
| `site7_install_sessions` | Cross-process Starter Kit install session state (survives subprocess boundaries, §15) | `uid` (unique), `starterKitHandle`, `status`, `data` (mediumtext JSON blob) | `uid` unique | — | `InstallationSessionService` | `InstallWizardController`, `InstallController`, `InstallationOrchestratorService` |
| `site7_installed_starter_kits` | Whole-Blueprint baseline snapshot per installed Starter Kit (the Phase 8 analog of `site7_installed_files`, at a different granularity — see §15) | `handle` (unique), `installedVersion`, `blueprintSnapshot` (mediumtext JSON) | `handle` unique | — | `InstalledStarterKitTrackingService` | `SynchronizationPlanner`, `UpdateCatalogService` |
| `site7_sync_history` | One row per Starter Kit sync attempt | `handle`, `fromVersion`, `toVersion`, `status`, `report` (mediumtext JSON) | — | — | `SynchronizationHistoryService` | `UpdateWizardController` |
| `site7_sync_sessions` | Cross-process Starter Kit sync session state | `uid` (unique), `handle`, `status`, `data` (mediumtext JSON) | `uid` unique | — | `SynchronizationSessionService` | `UpdateWizardController`, `SynchronizationOrchestratorService` |

**Two baseline concepts, deliberately not unified** (see also §23): `site7_installed_files` (per-file checksum, used by `PackageUpdatePlanner` for individual Section/Template package files like `template.twig`) and `site7_installed_starter_kits.blueprintSnapshot` (whole-Blueprint JSON, used by `SynchronizationPlanner` for native Craft resources at the whole-Starter-Kit level). Both implement the same *baseline/live/incoming three-way comparison pattern*, at two different granularities, with two independent implementations by design — see the `PackageUpdatePlanner` class docblock for the explicit reasoning (§24).

---

## 7. Package Model

A package is, physically, a directory: `packages/{handle}/` (or, for test fixtures only, `tests/fixtures/packages/{handle}/` — never scanned by production discovery).

```
packages/{handle}/
├── manifest.json          # REQUIRED - the single source of truth for package metadata (§8)
├── README.md              # Human-readable description (auto-generated for imports)
├── fields.yaml            # Section packages: captured field definitions
├── matrix.yaml            # Section packages: Entry Type/block definition referencing fields.yaml
├── template.twig          # Section packages: the REAL rendering Twig, mirrored from templates/_blocks/ (§17)
├── preview/
│   ├── preview-data.yaml  # Demo field values for the Library preview render
│   └── preview.{png,jpg,...}
├── demo/                  # (Some package types) demo content
├── resources/             # (Some package types) additional bundled resources
└── frontend/               # ONLY present if the package explicitly owns frontend files (§18) or captured
                            # whole-environment frontend config (Website/Starter Kit packages, §19) - never
                            # created automatically for a plain Section package
```

**Package source vs. installed resources — the核心 distinction**: everything under `packages/{handle}/` is Site7 Studio's own managed authoring storage (system-owned; safe to overwrite wholesale during import/sync/rollback, since a human never hand-edits it directly outside the Package Editor/Import flows). What that source produces on the **host site** — Craft Fields, Entry Types, and `templates/_blocks/{handle}.twig` — is genuinely developer-editable live content, and is the thing every safety mechanism in this document (baseline, three-way conflict, rollback protection) exists to protect. Confusing these two is the single most common architectural mistake — see the `PackageRollbackService` class docblock for the canonical statement of this distinction.

**Package types** (`models/packages/*.php`, each a ~10-line subclass of `Package` overriding only `getPackageType()`): `SectionPackage` (`'section'`), `TemplatePackage` (`'template'`), `PatternPackage` (`'pattern'`), `StarterKitPackage` (`'starter-kit'`), `ThemePackage` (`'theme'`). All actual structure lives in `PackageManifest`, not these subclasses (§8).

---

## 8. Package Manifest

`models/packages/PackageManifest.php` — `class PackageManifest extends craft\base\Model`. Backs `manifest.json`. Read via `PackageReader::readPackage()` → `new PackageManifest($decodedJsonArray)`; written by whichever service last touched the package (never a single "manifest writer" — `PackageAuthoringService::updatePackage()`, `MatrixEntryTypeImportService`, `SectionUpdateService`, `VersionManagerService`, etc. each write `manifest.json` directly via `json_encode`/`file_put_contents`).

**Backward compatibility rule**: every field added since the original schema is a plain public property with a safe default (`[]`, `null`, or a literal like `'1.0.0'`), added to the `'safe'` validation rule (never `'required'`). A manifest.json written before a given field existed simply has that field at its default after loading — confirmed by dedicated tests (`tests/unit/models/packages/PackageManifestTest.php`) for every schema generation. **No manifest migration has ever been required for a schema addition**, and none should be, per this convention.

| Property | Type | Required | Meaning | Written by | Read by |
|---|---|---|---|---|---|
| `schemaVersion` | string | required | Manifest schema version, currently always `'1'` | every writer | `PackageReader` (informational only — nothing branches on it) |
| `type` | string | required | `section`\|`template`\|`pattern`\|`starter-kit`\|`theme` | package creation | `PackageReader::createPackageInstance()`, `PackageManagerService::installPackage()` (type-specific cascade) |
| `handle` | string | required | Kebab-case, globally unique | package creation (never changes after) | everything |
| `name` | string | required | Display name | authoring/import | CP UI |
| `version` | string | required | Semver `MAJOR.MINOR.PATCH` | `VersionManagerService::createVersion()` (the only sanctioned writer of a *new* value — see §21) | `PackageManagerService`, `VersionManagerService`, `PackageUpdatePlanner` |
| `author`, `description`, `category`, `tags`, `compatibility` | string/array | optional | Basic metadata | `PackageAuthoringService::updatePackage()` | Library CP |
| `dependencies` | array | optional | `{sharedResources: string[], pluginDependencies: [{handle,requiredPlugin}], plugins, npmPackages, frontendTooling}` — the last three are Website/Starter-Kit-only, Phase 4 (§19) | `MatrixEntryTypeImportService`/`WebsiteImportService` | `DependencyResolverService`, `PackageManagerService::installPackage()` |
| `requires` | array | optional | Frozen Section/Pattern/Template dependency graph (package→package, by handle) | authoring/import | `PackageExportService::resolveDependencyClosure()` |
| `ownedFiles` | array | optional, **added Step 8.1** | `[{sourcePath, targetPath, type}]` — explicit package-owned files beyond the built-in Twig template (§18) | `MatrixEntryTypeImportService::captureOwnedFiles()`, `SectionUpdateService::syncOwnedFilesFromLiveSource()` (content only, never adds/removes entries) | `PackageManagerService::installOwnedFiles()`, `PackageUpdatePlanner::resolveArchiveEntryName()` |
| `importedFrom` | array | optional | `{sourceType, sourceId, sourceHandle, sourceUid, sourceHash, importedAt, importedBy}` — set only on imported packages | import services | `PackageAuthoringService` (locked-field checks), `SectionUpdateService`/`PageUpdateService` |
| `excludedFields` | array | optional | Fields detected but not captured (Platform Configuration/Review Required), shown for transparency | import services | Package Editor CP |
| `pages`, `globals` | array | optional | Starter-Kit-only: page references (never duplicated content) / captured Global Set values | `WebsiteImportService` | `StarterKitInstallationService` |
| `assetVolumes`, `categoryGroups`, `tagGroups`, `craftSections`, `navigation`, `projectConfigPaths` | array | optional, Starter Kit System Phase 1-3 | Captured native-resource settings (referenced-only, never a blanket project dump) | `WebsiteImportService`/`ProjectBuilder` | `BlueprintBuilder`, `CraftResourceInstallExecutor` |
| `displayName`, `company`, `website`, `supportUrl`, `documentationUrl`, `license`, `pricingType`, `minimumCraftVersion`, `minimumSite7Version`, `keywords` | string/array | optional, Phase 14 (Publishing metadata) | Marketplace/catalog display fields | `PackageAuthoringController::actionSaveMetadata()` | `PublishValidatorService` (quality checks) |

**Example** (real, simplified from `tests/fixtures/packages/test-hero/manifest.json`):
```json
{
  "schemaVersion": "1",
  "type": "section",
  "handle": "test-hero",
  "name": "Test Hero",
  "version": "1.0.0",
  "author": "Site7",
  "category": "Test",
  "tags": ["Hero"]
}
```

**Example with `ownedFiles`** (Step 8.1/8.2):
```json
"ownedFiles": [
  {
    "sourcePath": "frontend/src/css/components/ctaBanner.css",
    "targetPath": "frontend/src/css/components/ctaBanner.css",
    "type": "frontend-css"
  }
]
```
> **AI DEVELOPMENT NOTE**: `sourcePath`/`targetPath` are identical in every case produced by current code (the real relative path is mirrored verbatim, per §17's "preserve the real relative path" rule) — kept as two separate keys only so a future case that genuinely needs them to differ doesn't require a schema change. Do not assume they can currently diverge; nothing in the codebase writes them differently.

---

## 9. Package Authoring

`services/PackageAuthoringService.php` backs both the New Package wizard and the Package Editor.

- `createPackage(array $meta)`: validates `type`/`name`/`handle` (kebab-case regex), scaffolds `manifest.json` + `README.md` + `preview/`, calls `PackageManagerService::discoverPackages()`, sets `authoringStatus = 'draft'` (distinct from the discovery-default `'published'`, so a brand-new package is visibly a draft), and — critically — calls `PackageBackupService::backupToLocalRepository($handle)` immediately, so even an empty-shell package is instantly recoverable.
- `updatePackage(string $handle, array $fields)`: the single write path for manifest.json's editable metadata fields (name/description/category/tags/author/version/publishing-metadata). **Locked-field rule**: if the package `isLockedImportedSection()`/`isLockedImportedPage()`/`isLockedImportedWebsite()` (i.e., has a row in the corresponding `site7_*_import_sources` table), only `description`/`category`/`tags`/**`version`** may be written — `name`/`author` are silently dropped. `version` is deliberately allowed through this lock (fixed in Step 4) because a version bump is always system-computed (`VersionManagerService::createVersion()`), never a human hand-typing a string — the Package Editor's version field stays disabled in the UI regardless.
- Other authoring methods (`saveSectionFields`, `savePatternComposition`, `saveTemplateComposition`, `saveStarterKitComposition`, `savePreviewImage`) are scoped to their respective package type's own editable composition — see `PackageAuthoringController::actionEdit()` (§31) for the full aggregator.

**Three distinct package origins, same manifest shape, different edit rules**:
| Origin | Editable via Package Editor? | Version source |
|---|---|---|
| Manually authored (New Package wizard) | Fully editable | `VersionManagerService::createVersion()` (Publisher UI) |
| Imported (Section/Page/Website) | Locked except description/category/tags/version (see above) | `SectionUpdateService`/`PageUpdateService`/`StarterKitSyncService` (Sync From Source, §20) |
| Generated from existing Craft resources ("Save as Template") | Editable by the capturing user even outside Dev Mode (own-creator exception, `creatorId` column) | `VersionManagerService::createVersion()` |

---

## 10. Package Build / Export

```
Package (packages/{handle}/)
   ↓
PackageExportService::exportPackage($handle, $includeDependencies)
   ↓  resolveDependencyClosure()  — walks `requires` graph (+ Starter Kit page→template refs)
   ↓  PackageArchiveHelper::computeDirectoryChecksum()  — sha256-of-sorted-per-file-hashes, per bundled package
   ↓  ZipArchive: bundle-manifest.json + packages/{handle}/... (full directory copy, recursive)
   ↓
MarketplaceService::recordVersion($record, $checksum, $archivePath)  — dedup-safe on (packageId, version)
   ↓
site7_package_versions row: {version, checksum, archivePath}
```

**`.s7pkg` format**: a plain zip. Root entry `bundle-manifest.json` (a `PackageBundleManifest` model: `schemaVersion`, `generatedAt`, `rootHandle`, `rootType`, `craftVersion`, `site7Version`, `packages: [{handle,type,version,checksum}]`, `requiredSharedResources`). Then, for every package in the dependency closure, a full recursive copy under `packages/{handle}/...` — **exactly** what's on disk in `packages/{handle}/`, including any `frontend/` subfolder (owned files, §18) if present. `PackageArchiveHelper::addDirectoryToZip()` is a generic recursive copy with no knowledge of file types — this is why owned files required zero export-side code changes in Step 8.1/8.2.

**Checksum algorithm** (`PackageArchiveHelper::computeDirectoryChecksum()`): for every file under a package directory (excluding OS/editor cruft — `.DS_Store`, `Thumbs.db`, `.gitignore`, `.gitkeep`, `*.swp`/`*.tmp`/`*.bak`), compute sha256; sort by relative path; hash the joined `"path:hash\n"` lines. Deterministic regardless of mtime/permissions/OS. The single-file variant, `computeFileChecksum()` (added Step 3), is the exact same `hash_file('sha256', ...)` call, exposed standalone for comparing one file rather than a directory — used throughout the baseline/update/rollback system (§23-25). **There is exactly one checksum implementation in this codebase.**

**Dependencies included/excluded**: `exportPackage($handle, true)` (the default, used by publish/backup) bundles the full `requires` closure so the archive is self-contained. `VersionManagerService::createVersion()` deliberately calls `exportPackage($handle, false)` — a version row represents *this package's own* state, not a bundle of everything it requires (those are versioned independently by their own packages).

**Archive immutability**: once written, a `.s7pkg` file is never rewritten by any code path. `archivePath` on a `site7_package_versions` row is set exactly once (at `recordVersion()` time) and never updated except by `PackageBackupService::backupToLocalRepository()` repointing it when the *same* file is physically moved into `storage/site7-studio/marketplace-repo/` (§27) — the bytes never change, only the DB pointer to where they now live.

**How a version becomes restorable**: any code holding a `PackageVersionRecord` with a non-null `archivePath` pointing at an existing file can call `PackageArchiveHelper::extractZip($archivePath, $tempDir)` and get back the *exact* `packages/{handle}/` directory as it existed at that version — this is the entire mechanism `PackageRollbackService` relies on (§25).

---

## 11. Package Import

`services/PackageImportService.php` — imports a `.s7pkg` produced by export/publish (distinct from "Import Existing Section/Page/Website," which import *from a live Craft site*, §12-14).

```
.s7pkg file
   ↓
validatePackage($path)  — extracts to storage/runtime/site7-studio/import/{uuid}, reads bundle-manifest.json,
                            validates it, checksums each bundled package, classifies each as
                            newPackages / alreadyInstalled (checksum match) / conflicts (handle exists, checksum differs)
   ↓  (nothing written to @packages or the DB yet)
importPackage($validationResult, $options)
   ↓  for each bundled package not already-installed/skipped-conflict:
   ↓     PackageArchiveHelper::replaceDirectory($extractedSource, packages/{handle})  — wholesale directory replace
   ↓
PackageManagerService::discoverPackages()
   ↓
MarketplaceService::recordVersion() + syncDependencyRecords()  — for every bundled package
   ↓  (if $options['install'])
PackageManagerService::installPackage($rootHandle) [+ enablePackage()]
   ↓
PackageImportedEvent dispatched
```

`$options['overwriteConflicts']` (default `false`): without it, a handle collision with different content is left untouched and reported as skipped; with it, `PackageArchiveHelper::replaceDirectory()` deletes and replaces the existing `packages/{handle}/` wholesale. `MarketplaceService::updatePackage()` (the Marketplace "Update" button) always passes `true`.

> **AI DEVELOPMENT NOTE**: this directory-replace path operates on the package's own **source** directory (system-owned, §7) — it does not touch the host site's installed `templates/_blocks/{handle}.twig` at all. The *installed* side is completely separate and goes through `PackageManagerService::installPackage()` → `CraftResourceService::generateResources()`'s own content-compare guard (§16-17), which is unaffected by this method.

`PackageArchiveHelper::replaceDirectory()` (added Step 7) is the one shared implementation of "lay an extracted directory onto disk," reused identically by `PackageImportService` (here) and `PackageRollbackService::restorePackageSource()` (§25) — not two independent implementations.

---

## 12. Import Existing Section

The most detailed and most exercised import flow. Services: `MatrixEntryTypeImportService` (single Entry Type) and `CraftSectionImportService` (a native Craft Section with multiple Entry Types — delegates per-Entry-Type to the former).

```
Existing Craft site
   ↓
Existing Craft Section (optional layer) → Entry Type  ─────────────┐
   ↓                                                                │
Matrix field field-layout                                          │
   ↓  ResourceClassifierService::classifyFieldLayout() (§15)       │
   ↓  → capturable fields (FEATURE_RESOURCE/FEATURE_DEPENDENCY/     │
   ↓     NESTED_RESOURCE/REUSABLE_COMPONENT) written to fields.yaml/│
   ↓     matrix.yaml; SHARED_RESOURCE registered, never duplicated;│
   ↓     PLUGIN_DEPENDENCY/PLATFORM_CONFIGURATION/REVIEW_REQUIRED  │
   ↓     recorded to excludedFields, never captured                │
   ↓                                                                │
templates/_blocks/{entryType.handle}.twig (REAL file, if it exists)│
   ↓  copyTemplateTwigFromLiveSource() — is_file() + copy(),       │
   ↓  read-only against the source; stub fallback                  │
   ↓  ("<p>{{ block.x }}</p>" rows) ONLY if no real file exists    │
   ↓                                                                │
manifest.json written (importedFrom.sourceHash via                 │
   EntryTypeSourceHasher — structural hash, name excluded so a     │
   cosmetic rename alone doesn't trigger "Update Available")       │
   ↓                                                                │
SectionImportSourceRepository::record() — 1:1 packageId↔sourceUid  │
   ↓                                                                │
PackageManagerService::discoverPackages()→installPackage()→        │
   enablePackage()  (§16-17)                                       │
   ↓                                                                │
ResourceImportedEvent dispatched                                   │
   ↓                                                                │
PackageBackupSubscriber → PackageBackupService::backupToLocalRepository()
   ↓  (real .s7pkg archive + first site7_package_versions row,     │
   ↓   version "1.0.0", via the SAME export path as §10 — this is  │
   ↓   why a freshly-imported package already has one real,        │
   ↓   restorable version before any explicit "create version"     │
   ↓   ever runs)                                                   │
   ↓                                                                │
SITE7 package v1, with a real archive                              │
```

**Fallback behavior if Twig does not exist**: a generic stub (`<div class="site7-component {handle}">` wrapping one `<p>{{ block.x }}</p>` per capturable field) — this only happens when no matching `_blocks/*.twig` file exists at import time; it never happens for a section that's actually live-rendered.

**Duplicate-import guard**: `SectionImportSourceRepository::findBySourceUid($entryType->uid)` — an Entry Type can be imported exactly once; re-attempting throws with the existing package's handle. The only way to change an imported Section's content afterward is Sync From Source (§20).

**Where owned-file selection happens** (Step 8.1, §18): `MatrixEntryTypeImportService::importFromEntryType($entryTypeId, $meta)` accepts an optional `$meta['ownedFiles']` array of explicitly-selected Craft-root-relative paths — never auto-discovered from the field layout or filename matching.

---

## 13. Import Existing Page

`services/import/PageImportService.php` — the same shape as §12, at Entry (not Entry Type) granularity, producing a `TemplatePackage`.
- **What's imported**: the Entry's own field values (via `EntrySourceHasher`, which additionally hashes ordered Site7 Matrix block composition when present) — not structure (that's already covered by whatever Section packages the page's Matrix blocks reference).
- **Source identification**: the Entry's own `uid` — `PageImportSourceRepository`, 1:1 with `packageId`, same shape as `SectionImportSourceRepository`.
- **Package structure**: a `TemplatePackage` referencing the Section packages its Matrix blocks came from via `requires`, never duplicating their content.
- **Versioning/update**: `PageUpdateService::diff()`/`updateInPlace()` — same "compare live vs. stored, no-op if unchanged, one version if changed" pattern as `SectionUpdateService` (§20), field-values-only (no Twig-equivalent concept for a page).

---

## 14. Import Existing Website

`services/import/WebsiteImportService.php` — the whole-site capture entry point, producing a `StarterKitPackage`. This is the entry point into the **Starter Kit System** (§15), not a simple extension of §12-13.

**What's captured**: selected Entries (pages) + selected Global Sets, plus a **project-wide** environment snapshot (not scoped to the selection): `ComposerDependencyScanner::captureComposerPluginDependencies()` (every installed plugin), `FrontendToolingScanner::detect()`/`captureNpmDependencies()` (build system + npm deps), referenced-only native resources (Asset Volumes/Category Groups/Tag Groups/Craft Sections actually used by captured content — never a blanket project dump).

**Frontend/config capture**: `copyFrontendConfigFiles()` copies the detected config files (never source/build-output) into `packages/{handle}/frontend/` — see §19 for the full scope boundary of this (config-only) vs. §18's `ownedFiles` (explicit source files).

**Source tracking**: `WebsiteImportSourceRepository`, keyed by a computed `selectionKey` (sha256 of the sorted, deduplicated captured-entry uid list) since a website has no single natural source uid.

**Starter Kit behavior**: the captured `pages` array references Template packages by handle (never duplicates page content) — see §15 for how this becomes an installable `blueprint.json`.

---

## 15. Starter Kit / Native Craft Resource System

A parallel, architecturally distinct system from §7-14's single-package model — built for **whole-site** capture/install/sync, in 8 phases (fully detailed in `WEBSITE-STARTER-KIT-SYSTEM.md` + `PHASE-1` through `PHASE-8-*.md`; this section summarizes).

**Resource classification** (native Craft resources, project-wide — `ResourceClassifierService`, `CraftResourceDiscoveryService`): the same field-level classifier §12 uses (`ResourceClassifierService::classifyField()`, 10 classification constants), plus a higher-level **whole-Entry-Type** classifier (`CraftResourceDiscoveryService`) that buckets Entry Types into `PRESENTATION_SECTION`/`FEATURE_COMPONENT`/`SHARED_RESOURCE`/`UTILITY_COMPONENT`/`PLUGIN_COMPONENT`/`UNKNOWN` via a weighted, confidence-scored function — feeds the Import wizard's Select step only.

**Build pipeline**:
```
WebsiteImportService::importWebsite()  (capture, §14)
   ↓
ProjectBuilder  (assembles Project = manifest + CraftResourceRegistry graph + platform config)
   ↓
DependencyAnalyzer  (BFS closure over the whole-project graph, narrowed to what the manifest references;
                      reports cyclicResources explicitly rather than failing; produces 4 ordered waves:
                      plugins → schema → content → frontend)
   ↓
BlueprintBuilder  (writes blueprint.json — independent of manifest.json/.s7pkg shape;
                    includes a self-contained validation block: {valid, errors, warnings})
   ↓
StarterKitBuilder  (top-level entry point, build-only — never installs anything)
```
Only entry point: `MakeController::actionStarterKit()` (console) and, since a post-validation-report fix, `ResourceImportController::actionImportWebsite()` (§43 flags this as a fixed-but-not-independently-re-audited wiring).

**Installation pipeline** (Phase 6/7) — strictly separate planning/validation/execution:
```
blueprint.json
   ↓ InstallationPlanner::plan()  [read-only]           → InstallationPlan (fixed step order:
   ↓                                                        composer→plugin-install→craft-resource→
   ↓                                                        content→frontend→npm→project-config)
   ↓ InstallationValidator::validateInstallation() [read-only]
   ↓ InstallationSessionService::create()  — PERSISTED (site7_install_sessions)
   ↓ InstallationOrchestratorService::runToCompletion()
       spawns ONE FRESH SUBPROCESS PER STAGE (php craft site7-studio/install/run-stage <uid>)
       stages: composer → install (plugin-install+craft-resource+content+frontend+npm bundled) → project-config
   ↓ InstallationStageRunner::runNextStage()  (inside each subprocess — re-plans/re-validates cheaply,
   ↓                                            hands a filtered single-stage plan to:)
   ↓ InstallationExecutor::execute()  — dispatches per step type to executors/:
       ComposerExecutor, PluginInstallExecutor, CraftResourceInstallExecutor, ContentInstallExecutor,
       FrontendInstallExecutor, NpmExecutor, ProjectConfigExecutor
```

> **AI DEVELOPMENT NOTE — the subprocess architecture is not incidental, it is a documented, twice-independently-discovered bug fix.** `craft\services\Plugins::init()` caches `vendor/craftcms/plugins.php` for the life of one `Craft::$app` instance — running Composer install and `installPlugin()` in the *same* process reliably fails even though the plugin is genuinely on disk. A second, independent instance of the same "state written earlier in this process isn't visible to something else in this process" bug hit Project Config rebuild-after-plugin-enable. A generic Craft queue runner was tested and found **insufficient** (it loops multiple jobs in one long-lived process). Do not "simplify" this into a single in-process call — it will silently reintroduce both bugs. Full write-up: `PHASE-6-INSTALLATION-ORCHESTRATION.md`, `PHASE-7-FRESH-INSTALL-SETUP-WIZARD.md`.

**Synchronization/Update Engine** (Phase 8) — the Starter-Kit-level counterpart to §20/§24, at native-Craft-resource (not file) granularity:
```
site7_installed_starter_kits (baseline: version + full blueprintSnapshot)
   + newer blueprint.json
      ↓ SynchronizationPlanner::plan() [read-only] — per resource kind (Category/Tag Groups, Asset
      ↓   Volumes, Craft Sections), diffs OLD BLUEPRINT vs NEW BLUEPRINT vs LIVE CRAFT STATE:
      ↓     new-only → create step
      ↓     changed + live still matches old → safe update step
      ↓     changed + live has drifted → Conflict::TYPE_LOCALLY_MODIFIED, never auto-applied
      ↓     removed-from-new + live matches old → opt-in removal step (nothing removed until confirmed)
      ↓     removed-from-new + live has drifted → conflict, not silent delete
      ↓ SynchronizationValidator::validateSynchronization() [read-only]
      ↓ SynchronizationOrchestratorService::execute():
          1. strips every conflicted resource from the new Blueprint FIRST (documented bug: without
             this, the reused installer has no "conflict" concept and would overwrite it anyway)
          2. re-runs Phase 6/7's install machinery UNCHANGED against the filtered Blueprint
          3. applies confirmed removals via a SEPARATE subprocess (ResourceRemovalExecutor + one more
             ProjectConfigExecutor rebuild — a third independent instance of the same process-state bug)
          4. InstalledStarterKitTrackingService::recordSync() — advances baseline to a RECONSTRUCTED
             "what was actually applied" snapshot (conflicted resources kept at old value, unconfirmed
             removals kept), not the raw new Blueprint, so a conflict persists correctly into the next diff
```
Page content (`resources.pages`) is **explicitly never diffed field-by-field** — an informational note only, by design (comparing captured content safely without discarding local edits is out of this phase's scope).

> **AI DEVELOPMENT NOTE**: `PackageUpdatePlanner`/`InstalledFileBaselineService` (§23-24) implement the *same conceptual pattern* as `SynchronizationPlanner` above, but were built independently, later, at file-checksum granularity for individual package files. Both docblocks explicitly say the other "stays untouched and out of scope." Do not merge them without a very strong reason (§38) — they operate on structurally different data (Craft-resource field arrays vs. file checksums) and unifying them would be a large, risky refactor for unclear benefit.

---

## 16. Craft Resource Installation

Two install cascades exist, at two different scopes — do not confuse them:

**1. Package Engine install** (`PackageManagerService::installPackage()`, Section/Template/Pattern/Starter-Kit-as-library-entry packages):
```
installPackage($handle)
   ↓ resolve manifest.dependencies.sharedResources → DependencyResolverService::resolveSharedResources()
   ↓   (missing/dead Shared Resource → warning only, NEVER blocks install)
   ↓ type-specific cascade: Pattern requires Sections → recursively install+enable them;
   ↓   Template requires Patterns/Sections → same; Starter-Kit requires Templates → same
   ↓ if type === 'section': CraftResourceService::generateResources($packagePath)
   ↓   → createCraftField() for each fields.yaml entry — idempotent, getFieldByHandle() first
   ↓   → createMatrixEntryType() for each matrix.yaml block — idempotent, getEntryTypeByHandle() first
   ↓   → copy template.twig → templates/_blocks/{blockHandle}.twig (§17's content-compare guard)
   ↓   → InstalledFileBaselineService::record() for the template (§23)
   ↓ installOwnedFiles($record, $packagePath)  — Step 8.2, type-agnostic loop over manifest.ownedFiles (§18)
   ↓ record->status = 'installed'; DB transaction commit; invalidateCraftCaches()
   ↓ (on ANY failure: transaction rollback + craftResourceGenerator->removeResources() undo)
```
`enablePackage($handle)` is separate and required afterward to actually link a Section into the live Site7 Matrix field (`linkToMatrix()`) — install alone never does this.

**Idempotency**: both `createCraftField()` and `createMatrixEntryType()` look up by handle first and return the existing resource if found — installing an already-installed package (or a package whose Fields/Entry Types a different package already created) is always safe, never creates duplicates.

**Failure handling**: the whole install runs inside one DB transaction; any thrown exception rolls it back and calls `removeResources()` to undo whatever Craft resources were created in *this* call (never resources that pre-existed).

**2. Starter Kit installation** (`InstallationExecutor` + executors, §15) — a completely separate, subprocess-driven pipeline for whole-site provisioning (plugins, npm, native Craft resources, content). `CraftResourceInstallExecutor` specifically: creates/updates Asset Volumes/Category Groups/Tag Groups via official Craft service APIs (never raw YAML), and **only updates** an already-existing Section's settings — it never creates a Section/Entry Type from scratch (that remains the Package Engine's job, cascade #1 above, or manual CP work — see §43 for the current gap here).

---

## 17. Template File Lifecycle

**This is one of the most important lifecycles in the whole system — read this section before touching anything Twig-related.**

```
PACKAGE SOURCE                    packages/{handle}/template.twig
   ↓  (real file, mirrored from    (Site7-owned, safe to overwrite wholesale)
   ↓   the live _blocks/ file at
   ↓   import/sync time — never
   ↓   a stub unless no real file
   ↓   existed to copy)
   ↓
INSTALL                           CraftResourceService::generateResources()
   ↓                              copies template.twig → templates/_blocks/{blockHandle}.twig
   ↓                              ONLY IF the target is missing OR already byte-identical to the
   ↓                              source (content-compare guard — never overwrites a file it
   ↓                              doesn't recognize, even on a naive re-run)
   ↓
BASELINE                          InstalledFileBaselineService::record() — checksum of the file
   ↓                              exactly as it was just written, keyed (packageId, targetPath)
   ↓
[developer may edit templates/_blocks/{handle}.twig directly, on the live site — this is
 completely expected and fully supported]
   ↓
SYNC (Sync From Source, §20)      Only changes the PACKAGE'S OWN COPY (re-reads the live file
   ↓                              INTO the package) — never writes back to the live site during
   ↓                              sync. Creates a new VERSION if the live file differs from the
   ↓                              package's stored copy.
   ↓
VERSION (§21-22)                  Real archive + checksum, via VersionManagerService::createVersion()
   ↓
UPDATE (§24)                      PackageManagerService::updateInstalledFiles() — THIS is when the
   ↓                              live _blocks/ file can actually change, via the three-way
   ↓                              baseline/current/incoming comparison. A locally-modified file
   ↓                              is NEVER overwritten; a conflict is reported, not resolved silently.
   ↓
ROLLBACK (§25)                    Same three-way comparison, target = an older version's archived
   ↓                              content instead of a newer one. Same protection.
```

**Local modification**: any point after install where `computeFileChecksum(live file) !== baseline checksum` — detected exclusively by comparing against the *recorded baseline*, never by comparing to the package's *current* state (a two-way comparison cannot distinguish "the package changed" from "the developer edited it," which is exactly why the baseline table exists — see §23-24 for the full reasoning).

**Conflict**: baseline ≠ live AND baseline ≠ incoming → `PackageUpdatePlanner::RESULT_CONFLICT` — file is left completely untouched, reported, never auto-resolved.

**Deletion**: if the live file is missing entirely, `RESULT_LOCAL_DELETION` — **never silently recreated**, on the principle that a deliberate deletion is also a form of local intent that must not be silently overridden.

**Uninstall vs. permanent delete** (full detail in §26):
- **`PackageManagerService::removePackage()`** ("remove"/soft uninstall) — unlinks from the Site7 Matrix field, but **deliberately never touches `templates/_blocks/{handle}.twig` or the Entry Type/Fields at all** (so a later reinstall reuses the same Craft resources rather than orphaning existing content).
- **`PackageManagerService::deletePackage()`** ("delete"/permanent) — calls `CraftResourceService::removePackageResources()`, which **does** attempt to remove the installed template file, but only if it still byte-matches the package's own `template.twig` (same content-compare guard as install) — a locally-modified file is left in place and reported as skipped, never destroyed.

> **AI DEVELOPMENT NOTE**: `templates/site7-components/` is **confirmed dead** — no current code path reads from or writes to it. `CraftResourceService::generateResources()`/`removePackageResources()` both explicitly target `@templates/_blocks/` with a code comment repudiating the old sandbox by name. Do not resurrect it, and do not document it as an active rendering path (see the discrepancy note at the top of this document).

---

## 18. Owned Files Architecture

Introduced Step 8.1 (metadata model + explicit import-time capture) and completed Step 8.2 (full install/sync/update/rollback lifecycle integration).

**The model**: `PackageManifest::$ownedFiles = [{sourcePath, targetPath, type}]` (§8). `sourcePath` is relative to `packages/{handle}/`; `targetPath` is relative to the Craft install root; both are identical in every case current code produces (real relative path mirrored verbatim). `type` is `'frontend-css'`\|`'frontend-js'`\|`'asset'` — display-only, nothing in the pipeline branches on it.

**Explicit ownership, never inferred — this is the single most important rule of this subsystem.** No code anywhere matches a CSS/JS filename against a component handle to guess ownership. The reason is architectural, not a limitation: the real RP Craft host site organizes CSS/JS by UI role (`button.css`, `card.css`, `header.css`), not per-component — a filename-matching heuristic would either find nothing for most components (harmless but useless) or, worse, misattribute a genuinely shared file (used by many components) to just one package, which could later cause that shared file to be "safely removed" or rolled back as if it belonged to only one thing. See `plugins/site7-studio/docs/../..` (Step 8A audit) for the full live-code evidence behind this decision.

**Discovery vs. ownership** — two separate steps, never conflated:
- **Discovery**: `FrontendToolingScanner::listCandidateFrontendFiles($frontendRoot)` — lists CSS/JS files under the detected frontend root's `src/`, relative to the Craft root. Returning a path here **does not** make it owned by anything. Exposed via `ResourceImportController::actionListFrontendFileCandidates()`.
- **Ownership**: `MatrixEntryTypeImportService::captureOwnedFiles($packagePath, $selectedPaths)` — copies **only** the paths a caller explicitly passed (via `$meta['ownedFiles']` on `importFromEntryType()`), validates each is a plain relative path with no `..` traversal, skips (with a logged warning, never a fatal import failure) any path that doesn't exist on disk.

**Lifecycle integration** (each stage reuses existing infrastructure, no owned-file-specific system was built):

| Stage | Mechanism | Notes |
|---|---|---|
| Install | `PackageManagerService::installOwnedFiles()` | Type-agnostic loop, same content-compare guard as the template copy (§17); one `InstalledFileBaselineService::record()` per file, `resourceHandle` = the package's own handle |
| Baseline | `site7_installed_files` (same table, §23) | No new table — schema already fit `(packageId, resourceHandle, targetPath, installedVersion, checksum)` exactly |
| Sync | `SectionUpdateService::diffOwnedFiles()`/`MatrixEntryTypeImportService::syncOwnedFilesFromLiveSource()` | Same `computeFileChecksum()` convention as the Twig diff; folded into the SAME single "did anything change" decision fields/Twig already use — **multiple changed owned files in one sync still produce exactly ONE new version**, verified live (§20) |
| Versioning | `VersionManagerService::createVersion()` (unchanged) | Owned files are just more files under `packages/{handle}/` — `PackageExportService`/`PackageArchiveHelper` already zip/checksum them automatically |
| Update | `PackageUpdatePlanner::resolveArchiveEntryName()` (§24) | Reads the *target archive's own* bundled `manifest.json` for its `ownedFiles`, correctly handling a version whose owned-file set genuinely differs from what's on disk today |
| Conflict | `PackageUpdatePlanner::classify()` (unchanged, §24) | The six-case decision table has no concept of "file type" — an owned CSS file and a Twig file are classified identically |
| Rollback | `PackageRollbackService` (zero code changes needed) | Already restores the whole `packages/{handle}/` directory wholesale, and already delegates the installed-file side to the now-generic `updateInstalledFiles()` |

---

## 19. Frontend Tooling

`services/FrontendToolingScanner.php` — read-only detection, two distinct methods with two distinct purposes:

- **`detect()`/`detectAt()`**: finds the frontend project root (checks `''`, `'frontend'`, `'assets'`, `'theme'` as candidate directories for a `package.json`), identifies the build system (`vite`/`webpack`/`gulp`/`tailwind`/`plain`, via a fixed config-filename allow-list — including a Tailwind v4 fallback that checks the npm dependency directly since v4 configures itself via CSS, not a config file), and lists the config filenames present. **Config content only — never `node_modules`, never build output, never CSS/JS source.**
- **`captureNpmDependencies($root)`**: reads `package.json` `dependencies`/`devDependencies` into `[{name, version, dev}]`.
- **`listCandidateFrontendFiles($frontendRoot)`** (added Step 8.1): lists actual CSS/JS **source** files under `{root}/src/` — this is the one method that looks at source files, and it exists purely for the owned-files discovery step (§18), never for automatic capture.

**What Site7 Studio captures automatically** (Website/Starter Kit import, `WebsiteImportService::copyFrontendConfigFiles()`, §14): the detected config files only, copied verbatim into `packages/{handle}/frontend/`.

**What it does NOT capture automatically**: any CSS/JS source file, any compiled/build output (`web/themes/front/` or equivalent), any font/image asset — none of these are ever captured without an explicit `ownedFiles` selection (§18).

**DETECTED vs. PACKAGE-OWNED — the critical distinction**:
| | DETECTED | PACKAGE-OWNED |
|---|---|---|
| What | Build system, config files, npm deps, candidate source file list | Specific files a human explicitly selected |
| Where recorded | `manifest.dependencies.frontendTooling`/`npmPackages` (whole-environment, Website packages only) | `manifest.ownedFiles` (Step 8.1, any package type) |
| Triggers install/baseline/versioning? | No — informational only, "no install logic reads these yet" (per `PackageManifest`'s own docblock, still true) | Yes — full lifecycle integration (§18) |
| Automatic? | Yes, scan-based | **Never** — explicit selection only |

---

## 20. Sync From Source

`SectionUpdateService::diff()`/`updateInPlace()` (Section packages — the primary, most-exercised path); `PageUpdateService` (Page packages, field-values only); `StarterKitSyncService` (Website packages, delegates to §15's Synchronization Engine).

```
Live Craft site (Entry Type field layout + templates/_blocks/{handle}.twig + any owned files)
   ↓
diff($packageHandle):
   fieldsDiff  = compareFields(package's fields.yaml, live field layout)   → {added, removed, changed, unchanged}
   twigDiff    = diffTwig(package's template.twig, live _blocks/ file)     → {changed, liveChecksum, packageChecksum}
   ownedFilesDiff = diffOwnedFiles(package's ownedFiles, live copies)      → [{targetPath, changed, ...}]
   ↓
updateInPlace($packageHandle):
   if NOTHING changed (no field diff, twig unchanged, no owned file changed):
        → return immediately. NO file writes. NOT EVEN the source-hash bookkeeping is touched.
        → NO NEW VERSION. Calling this repeatedly with no source change is a true no-op.
   else:
        → write fields.yaml/matrix.yaml (always, if fields changed)
        → copy live Twig → package's template.twig (only if twigDiff.changed)
        → copy live owned files → package's copies (only the ones diffOwnedFiles flagged as changed)
        → re-sync SectionImportSourceRepository (new structural sourceHash)
        → packageManager->installPackage() (re-syncs Craft field layout; idempotent Twig re-copy, §17)
        → bumpType = 'minor' if any field added/removed, else 'patch'
        → VersionManagerService::createVersion($handle, $bumpType, summarizeChanges(...))
              → EXACTLY ONE new version, real archive, real checksum (§21)
        → previous versions completely untouched (§22)
```

**The rule, verified live (Step 8.2 verification, 32/32 checks passed)**: any combination of field changes + Twig change + N changed owned files in one sync produces **exactly one** new `site7_package_versions` row — never one per changed thing. This falls out naturally because the "did anything change" check and the `createVersion()` call are both singular, not per-file.

**Bump-type rule** (verified against `SectionUpdateService::updateInPlace()`'s literal code, not assumed): a field **added or removed** → `minor`; anything else (field type/instructions change, Twig-only change, owned-file-only change) → `patch`. There is no automatic `major` bump anywhere in this codebase — `VersionManagerService::bumpVersion()` only ever receives `'patch'`/`'minor'` from sync; a `major` bump is only ever chosen explicitly, by a human, via the Publisher UI's `PackagePublisherController::actionCreateVersion()`.

---

## 21. Version Management

`services/publishing/VersionManagerService.php`, implements `VersionManagerInterface`.

```php
createVersion(string $handle, string $bumpType, ?string $releaseNotes = null): PackageVersionRecord
```
1. Validate `$bumpType` ∈ `{patch, minor, major}`.
2. **Bump base** = `max($record->version, highest version ever recorded for this package in site7_package_versions)` — **not** just the manifest's current value. This was a Step 7 fix: after a rollback restores an older manifest, bumping off the manifest alone would produce a version string that already exists in history (e.g. rolled back to `1.0.0`, next patch tries `1.0.1`, which a later version already used) — `resolveBumpBaseVersion()` prevents this by always looking at the full history, not just the current manifest.
3. Compute `$newVersion` via `bumpVersion()` (strict semver regex `^\d+\.\d+\.\d+(?:[-+].*)?$`, resets lower components to 0 — standard semver).
4. Write through `PackageAuthoringService::updatePackage($handle, ['version' => $newVersion])` — the exact same manifest-write path every other metadata edit uses.
5. Call `PackageExportService::exportPackage($handle, includeDependencies: false)` — this **is** the version-recording path: it computes the real checksum and writes the real `.s7pkg`, then calls `MarketplaceService::recordVersion()` internally (dedup-safe on `packageId+version`).
6. Re-query the just-created (or already-existing, if dedup fired) `PackageVersionRecord`; if none is found, **throw loudly** rather than return a version with no real archive behind it.
7. Set `releaseNotes` on that row if given.
8. Dispatch `VersionCreatedEvent`.

> **AI DEVELOPMENT NOTE**: do not bypass `PackageExportService`/`MarketplaceService::recordVersion()` to hand-build a `PackageVersionRecord`. This was literally the Step 4 bug (`createVersion()` used to insert a bare record with no checksum/archivePath) — fixed by routing through the existing export/record path, not by adding a second one.

**Duplicate prevention**: `MarketplaceService::recordVersion()` — `if (PackageVersionRecord::find()->where(['packageId'=>...,'version'=>...])->one()) return;` — the single dedup guard every version-creation path (sync, manual bump, import) relies on.

**Imported package version locks**: see §9 — `version` is the one exception explicitly carved out of the import-lock, specifically so `createVersion()` (called from Sync From Source) works on imported packages, which is the primary real-world case.

**Rollback interaction**: covered in point 2 above and in §25 — rolling back then syncing again always produces a genuinely new version number, verified live.

---

## 22. Version History / Archives

`site7_package_versions` + `.s7pkg` files. **Immutability is structural, not a convention** — no code path in this repository ever calls `PackageVersionRecord::updateAll()`/`->save()` on an existing row's `version`/`checksum`/`archivePath` after creation (the one exception, `PackageBackupService` repointing `archivePath` when the *same bytes* physically move into `marketplace-repo/`, §27, never changes the checksum or the file contents). No code path ever overwrites or deletes an existing `.s7pkg` file.

```
v1 created → archive1.s7pkg, checksum1
v2 created → archive2.s7pkg, checksum2   ← v1's row/file: UNTOUCHED
v3 created → archive3.s7pkg, checksum3   ← v1 AND v2's rows/files: UNTOUCHED
```

**Why v1 must remain unchanged when v3 is created**: it's the only thing that makes rollback (§25) meaningful — restoring "v1" only means something if v1's archive is guaranteed to still contain exactly what it contained when v1 was created. Verified directly, repeatedly, across Steps 6/7/8.2's live tests: every historical version's `archivePath`, `checksum`, and actual file bytes (`md5_file()` of the archive) were re-checked byte-for-byte identical after every subsequent version/update/rollback operation.

---

## 23. Installed File Baseline

`site7_installed_files` + `InstalledFileBaselineService` (`services/synchronization/InstalledFileBaselineService.php`).

**Why baseline is different from package checksum**: the package checksum (`site7_package_versions.checksum`, §22) describes the package's **source state at a version** — it says nothing about what's currently on the *host site's* disk. The baseline describes exactly that: the checksum of a specific installed file **as it existed immediately after Site7 Studio last wrote it**. Two files can have the identical package checksum but completely different baselines if the same package was installed on two different sites at different times, or if a site's copy was later hand-edited. A two-way comparison (installed file vs. package's current state) cannot distinguish "the package changed since install" from "the developer edited the installed file" — both would look like "installed file ≠ package's current file." The baseline is the third fact that resolves this ambiguity (§24).

**When created**: `InstalledFileBaselineService::record()`, called from exactly two places — `PackageManagerService::installPackage()` (for the built-in template, and via `installOwnedFiles()` for each owned file, §18) and `PackageManagerService::applySafeFileUpdate()` (advancing the baseline after a verified safe update, §24).

**Upsert semantics**: unique index `(packageId, targetPath)` — reinstalling the same file never accumulates a second row; `record()` always updates the existing row in place.

**Reinstall**: idempotent — re-running `installPackage()` on an unchanged package produces the same baseline checksum, no duplicate row (verified live, Step 5).

**Uninstall/permanent delete**: `removePackage()` (soft) never touches baseline rows (the files themselves are untouched, so the baseline describing them is still accurate). `deletePackage()` (permanent) relies on the `ON DELETE CASCADE` FK from `site7_installed_files.packageId` → `site7_packages.id` — no explicit cleanup code needed, confirmed by the FK definition in the migration and by the Step 5 live test showing zero manual deletion calls were required.

---

## 24. Update / Three-Way Conflict System

`services/synchronization/PackageUpdatePlanner.php` (the decision engine) + `PackageManagerService::updateInstalledFiles()` (the executor). This is the safety core of the entire package lifecycle — read this section fully before modifying anything that writes to an installed file.

**The three inputs, per tracked file**:
- **BASELINE (A)** — `site7_installed_files.checksum` — what Site7 Studio actually wrote last.
- **CURRENT (B)** — `PackageArchiveHelper::computeFileChecksum()` of the live file, right now.
- **INCOMING (C)** — the checksum of the target version's file, read from that version's own archived `manifest.json` (§18's `resolveArchiveEntryName()`) — never assumed from the current on-disk package.

**The decision table** (`PackageUpdatePlanner::classify()`, the actual code, unmodified since Step 6 — verified via a dedicated 14-test unit suite plus 4 more for the Step 8.2 resolver extension):

| A vs B | A vs C | Result | Action |
|---|---|---|---|
| = | = | `UNCHANGED` | nothing to do |
| = | ≠ | `SAFE_UPDATE` | write incoming, verify written checksum, advance baseline |
| ≠ | = | `LOCAL_MODIFICATION` | never touched, never overwritten |
| ≠ | ≠ (even if B=C) | `CONFLICT` | never touched, reported for manual resolution |
| B missing | (any C) | `LOCAL_DELETION` | never silently recreated |
| (any B) | C missing (incoming dropped this file) | `SAFE_REMOVAL` if B=A, else `REMOVAL_CONFLICT` | file removed only if it still matched baseline |

> **AI DEVELOPMENT NOTE**: `CONFLICT` fires even when B happens to equal C (both diverged from baseline independently, by coincidence landing on the same content) — this is deliberate, tested behavior (`testCase4ConflictEvenWhenLiveAndIncomingCoincidentallyMatchEachOther`), not a bug. Do not "optimize" this into treating B=C as safe without re-reading the rollback-specific exception below.

**Execution** (`PackageManagerService::updateInstalledFiles($handle, $toVersionRecordId)`):
1. Requires the target version to have a real, existing `archivePath` — throws otherwise.
2. Builds the plan via `PackageUpdatePlanner::plan()` (one `classify()` call per baseline row).
3. For `SAFE_UPDATE`: extracts the real bytes from the target archive, writes them, **verifies the written file's checksum matches exactly what was promised before ever advancing the baseline** — a failed/partial write never looks identical to a successful one.
4. For `SAFE_REMOVAL`: deletes the file, removes the baseline row (`InstalledFileBaselineService::remove()`).
5. Every other result: `applied = false`, nothing written, nothing advanced. **Partial results are always reported per-file** — the return array is one entry per file with its own `result`/`applied`, never a single pass/fail flag for the whole operation.

> **AI DEVELOPMENT NOTE — a real, discovered footgun**: an earlier draft of `updateInstalledFiles()` called `PackageBackupService::backupToLocalRepository()` before applying safe changes, per general "back up before destructive changes" instinct. This was **removed** after live testing proved it actively harmful: that service keeps only the *latest* backup per handle, deleting the previous one — which silently destroyed an *older* `PackageVersionRecord`'s own `archivePath` whenever that archive happened to already live in `marketplace-repo/` (true for a package's very first version). Every version's own Step-4 archive is already this operation's real safety net. **Do not call `PackageBackupService` from any code path that also creates/holds multiple independent version archives.**

---

## 25. Rollback

`services/publishing/PackageRollbackService.php` — restores a package to a previously recorded immutable version.

```
Version archive (site7_package_versions.archivePath for the TARGET version)
   ↓
restorePackageSource($handle, $archivePath):
   extractZip() the whole archive to a temp dir
   PackageArchiveHelper::replaceDirectory(extracted packages/{handle}/, live packages/{handle}/)
      — WHOLESALE replace. Safe because packages/{handle}/ is Site7-owned (§7), not developer-edited.
      Deliberately does NOT call PackageBackupService (same reasoning as §24's note).
   ↓
DB/package state:
   PackageManagerService::discoverPackages()  — re-syncs PackageRecord.version from the restored
   manifest.json, so "package-owned state restored to the snapshot" is genuinely true afterward
   ↓
Installed files:
   PackageManagerService::updateInstalledFiles($handle, $toVersionRecordId)  — the EXACT SAME
   method §24 uses for a forward update. It does not know or care whether the target version is
   newer or older than what's currently installed — this is genuine reuse, not a lookalike.
   ↓
Three-way safety:  IDENTICAL to §24's table — a locally-modified installed file is never
   overwritten by a rollback any more than by a forward update.
   ↓
Baseline:  advanced only for files actually restored, exactly as §24.
```

**Rollback-specific refinement** (`reconcileAlreadyMatchingConflicts()`, layered on top, NOT inside `classify()` itself — §24's decision table stays exactly as tested): if the base planner reports `CONFLICT` but the live file's checksum happens to **already exactly equal** the rollback target's content, there's nothing destructive left to do — no write happens (nothing to write), but the baseline is corrected to reflect that reality, so a later comparison doesn't keep reporting an already-resolved file as an open conflict. This is the one deliberate divergence from §24's general rule, scoped narrowly to rollback and implemented as a post-processing step so forward `updateInstalledFiles()` calls are completely unaffected.

**No new version is created**: confirmed structurally — `PackageRollbackService::rollback()` never calls `VersionManagerService::createVersion()` or `MarketplaceService::recordVersion()` anywhere in its code path.

**Historical version/archive immutability**: guaranteed by §22 — rollback only ever *reads* an existing `archivePath`, never writes to any `PackageVersionRecord` row.

**Deleted files, conflicts**: identical handling to §24 (`LOCAL_DELETION` never recreated, `CONFLICT`/`REMOVAL_CONFLICT` never auto-resolved).

**Post-rollback future version numbering**: see §21 point 2 — `VersionManagerService`'s bump-base-off-history fix specifically exists for this scenario, verified live (rolling back to v1 then syncing produced v1.0.3, not a colliding v1.0.1, when v2/v3 already existed).

---

## 26. Uninstall / Delete

Four genuinely distinct operations — do not conflate them.

| Operation | Method | Package record | Package source (`packages/{handle}/`) | Installed templates/`_blocks/` | Owned files | Baselines | Versions/archives | Craft resources (Fields/Entry Types) |
|---|---|---|---|---|---|---|---|---|
| **Uninstall** ("remove") | `PackageManagerService::removePackage()` | `status → 'available'` | untouched | **untouched** (deliberately — see §17) | untouched | untouched | untouched | unlinked from Matrix field only; Fields/Entry Types left intact |
| **Delete** (Dev-Mode, usage-checked) | `PackageManagerService::deletePackage()` | row deleted | directory deleted from disk | removed **only if still byte-matches package's own copy** (§17); locally-modified files left in place + reported | same content-compare guard applies | cascade-deleted (FK) | cascade-deleted rows; **archive files on disk are NOT deleted** (only DB rows) | removed only if `Entry::find()->typeId()->count() === 0` (Entry Types) / `findFieldUsages()` empty (Fields) — otherwise skipped + reported |
| **Detach** (Dev-Mode strict, no exception) | `PackageManagerService::detachPackage()` | row deleted | directory deleted (same as delete) | **never touched at all** | never touched | cascade-deleted (FK) | cascade-deleted | **never touched** — the explicit "undo an import by mistake, without touching what it linked to" path |
| **Disable** | `PackageManagerService::disablePackage()` | `status → 'disabled'` | untouched | untouched | untouched | untouched | untouched | unlinked from Matrix field (same as remove) |

**Usage-safety checks** (before delete/remove is even attempted): `PackageUsageService::getUsage($handle)` — counts real Entry elements using the package's Entry Type and real field-layout usages of its Fields; `PackageActionController` blocks the action in the CP if usage > 0, requiring explicit confirmation. `CraftResourceService::removePackageResources()` re-checks this itself at the Craft-resource level (`Entry::find()->typeId($entryType->id)->status(null)->count()`, `Craft::$app->getFields()->findFieldUsages($field)`) as the authoritative, defensive final check — never trusts the caller's earlier check alone.

> **AI DEVELOPMENT NOTE**: `deletePackage()` does not delete the `.s7pkg` files themselves from `storage/site7-studio/exports/`/`marketplace-repo/` — only the DB rows referencing them. This is a known, minor storage-growth characteristic, not a bug in the safety model (the archives being "orphaned" is harmless; deleting them proactively would risk destroying a backup someone still needed). See §43.

---

## 27. Backup / Local Repository

`services/support/PackageBackupService.php` + `events/subscribers/PackageBackupSubscriber.php` + `storage/site7-studio/marketplace-repo/` (also read by `LocalMarketplaceRepository`, §29).

```
ResourceImportedEvent dispatched (every "Import Existing X" flow, §12-14)
   ↓
PackageBackupSubscriber::onResourceImported()
   ↓
PackageBackupService::backupToLocalRepository($handle)
   ↓  PackageExportService::exportPackage($handle, true)  — full closure, real archive+checksum
   ↓  ensures storage/site7-studio/marketplace-repo/ exists
   ↓  deletes any PREVIOUS backup for this handle (matched by reading each candidate .s7pkg's own
   ↓     bundle-manifest.json rootHandle — not just filename prefix, to avoid a false match against
   ↓     a different package whose handle happens to share this one as a prefix)
   ↓  rename()s the fresh export into marketplace-repo/ — KEEPS ONLY THE LATEST BACKUP PER HANDLE,
   ↓     no accumulated history
   ↓  repoints the just-recorded PackageVersionRecord.archivePath from the temp export path to the
   ↓     final repo path (PackageVersionRecord::updateAll()) — same bytes, new location
```

**Also triggered by**: `PackageAuthoringService::createPackage()` (even an empty-shell new package gets backed up immediately).

**Retention behavior — the one important safety limitation**: **latest-only, per handle.** This is exactly why `updateInstalledFiles()`/`rollback()` deliberately never call this service (§24's AI note) — calling it mid-lifecycle, when multiple independent version archives already exist, can delete an *older version's own* archive if that archive happens to be the one currently sitting in `marketplace-repo/`. Confirmed to actually happen live during Step 6's own verification before the fix.

**Failure isolation**: every call is wrapped in try/catch; a failed backup is only `Craft::warning()`-logged, never blocks the create/import it's piggybacking on.

---

## 28. Dependencies

Two dependency graphs, both forward-edge-only (never reciprocal), both stored in `site7_package_dependencies` with a `dependencyType` discriminator:

1. **Package → Package** (`requires`): frozen at import/authoring time, resolved by `PackageExportService::resolveDependencyClosure()` (BFS over `requires`, type-specific: Pattern→sections, Template→patterns/sections, Starter-Kit→templates+page template refs) and by `PackageManagerService::installPackage()`'s type-specific cascade (§16). **Circular dependencies**: not specially detected in the package-to-package graph (unlike the Shared Resource graph, §15's `DependencyAnalyzer::analyzeCycles()`, which does report them explicitly for the whole-project native-resource graph).

2. **Package → Shared Resource** (`dependencyType = 'sharedResource'`): `DependencyResolverService::resolveSharedResources()` — a BFS/queue resolution over `site7_shared_resources`/`site7_shared_resource_dependencies`, run **before** the type-specific cascade at install time. A missing or dead (no-longer-live-in-Craft) Shared Resource is **never a blocking error** — always a warning, collected in `PackageManagerService::$_lastInstallWarnings` and `Craft::warning()`-logged, left for manual resolution via the Shared Resources Library (Import/Create/Skip).

**Dependency discovery**: `SharedResourceRegistryService::registerField()`/`registerIfMissing()` — a field is registered once it's classified `SHARED_RESOURCE` by `ResourceClassifierService` (§15) during any import.

**Version constraints**: `PackageDependencyRecord.minimumVersion` exists as a column but is not currently enforced by any resolver — informational only as of this document's last verification.

**Dependency archive behavior**: `exportPackage($handle, includeDependencies: true)` (used by publish/backup, never by `createVersion()`, §10/§21) bundles the full closure into one `.s7pkg` so a distributed archive is self-contained; Shared Resources themselves are **never** bundled (they must already exist live on the installing site) — only a manifest-level declaration of what's needed.

---

## 29. Marketplace / Commerce / Licensing

**Two "repositories," one interface, two very different implementations** (`MarketplaceRepositoryInterface`, auto-registered by `MarketplaceService::init()`):

| | `LocalMarketplaceRepository` | `Commerce24MarketplaceRepository` |
|---|---|---|
| Backing | `storage/site7-studio/marketplace-repo/` folder | Real HTTP calls via `CommerceClient` (Guzzle) |
| Network | None | `GET /marketplace/catalog`, `GET /marketplace/download/{handle}` |
| Auth/gating | None | Requires `commerceApiEndpoint`+API key configured; returns `[]` (not an error) if unconfigured |
| Status | **Always available**, zero configuration | **Real, fully-implemented client code** against a documented API contract — its *liveness* depends entirely on an actual reachable Commerce24 server existing, which this codebase cannot itself confirm |

Publish-side counterparts (`PackagePublishTargetInterface`): `LocalPublishTarget` (file copy into the same local folder) and `Commerce24PublishTarget` (`POST /marketplace/publish`), auto-registered by `RepositoryManagerService`.

**`CommerceClient`** (`services/commerce/CommerceClient.php`) — the one real implementation of `CommerceClientInterface`: Guzzle client, `base_uri` from plugin Settings, headers `Authorization: Bearer {apiKey}` / `X-Site7-Environment` / `X-Site7-Store`, GET-caching tagged `commerce24` (invalidated on any mutating call). **This is real, working code, not a stub.**

**Commerce business services** (all real, all degrade gracefully to safe defaults when Commerce24 is unconfigured/unreachable — never throw uncaught): `LicenseService`, `PlanService`, `SubscriptionService`, `commerce\PackageService`, `DownloadService`, `UpdateService`, `FeatureGateService`.

**Entitlement enforcement — real business logic, layered on top of, never replacing, the core Package Engine**:
- `commerce\PackageService::isEntitled($handle)` — purchased, or free, or included in the current plan.
- `MarketplaceService::installFromRepository()` enforces `isEntitled()` **only** for `Commerce24MarketplaceRepository` — `LocalMarketplaceRepository` packages are never entitlement-gated.
- `PackageService::syncEntitlements(PlanInfo $plan)` — disables anything no longer covered by the plan, marking `entitlementRemovableOn = now + 14 days` (`GRACE_PERIOD_DAYS`); re-enables only packages carrying that marker (never a manually-disabled-for-other-reasons package).
- `FeatureGateService::allows($feature)` — resolves the current plan's `features[]`; if no plan resolves at all, **fails closed** to `Settings::$commerceOfflineFeatures` (empty by default).

**The ONE genuinely stubbed piece**: package **signing** (`PackageSignerInterface`/`NullPackageSigner`) — `isEnabled()` always `false`, `sign()` always `null`, `verify()` always `true`. Explicitly documented as an extension point, not cryptography deferred by oversight. `site7_package_publications.signature` exists and is reserved but never populated.

**Separation, restated clearly**: production Commerce24 behavior = real HTTP calls, real caching, real entitlement math, contingent on a configured+reachable server. Local/offline behavior = `LocalMarketplaceRepository`/`LocalPublishTarget`, zero network, always available — this is not a "test mock," it's a first-class, permanently-supported repository type.

Full design rationale: `PHASE-24-COMMERCE-LICENSING.md`.

---

## 30. Package Publishing

`services/publishing/PackagePublisherService::publish()` — full flow:
```
optional version bump (VersionManagerService::createVersion)
   ↓ optional metadata save (PackageAuthoringService::updatePackage)
   ↓ PublishValidatorService::validatePackage()
       hard errors (block publish): missing package/manifest, unresolvable dependency closure
       quality score (0-100, 7 checks, never blocks alone): README, preview image, metadata
       completeness, type-specific required assets, kebab-case handle, semver version,
       minimumCraftVersion/minimumSite7Version set
   ↓ PackageBuilderService::build()  — delegates zip/checksum/closure to PackageExportService (§10)
       ADDS: package.json (npm-style descriptor), CHANGELOG.md (from version history),
       LICENSE.md (from manifest.license, if set)
   ↓ RepositoryManagerService::getTarget($repositoryHandle)->publishPackage()
   ↓ PackageSigner::sign()  — always NullPackageSigner today, no-op
   ↓ authoringStatus → 'published' (the ONLY place this transition happens)
   ↓ PublishHistoryService::recordPublish()  — one row per attempt, into site7_package_publications
```

---

## 31. Controllers / CP UI

15 CP controllers (`src/controllers/`) + 5 console controllers (`src/console/controllers/`). Dedicated CP GET routes are registered explicitly in `Site7Studio::attachEventHandlers()`; every mutating/JSON action is reached via Craft's default `site7-studio/<controller-id>/<action-id>` resolution with no dedicated route (the plugin's consistent convention).

| Screen/Feature | Controller | Key actions → Service |
|---|---|---|
| Dashboard | `DefaultController` | `actionIndex()` → `PackageManagerService::getAllPackages()` |
| Library (grid/detail/preview) | `LibraryController` | `actionPackage()` → `PackageManagerService`, `PackageUsageService`, `PublishHistoryService`, `VersionManagerService::getVersionHistory()` |
| New Package / Package Editor | `PackageAuthoringController` | `actionCreate()`/`actionSave()` → `PackageAuthoringService` (§9); **Dev-Mode-gated** with a self-captured-Template exception |
| Package lifecycle actions | `PackageActionController` | `actionInstall/Enable/Disable/Remove/Delete/Detach()` → `PackageManagerService` (§16, §26); `Delete`/`Detach` Dev-Mode-gated |
| Marketplace (5 tabs) | `MarketplaceController` | `actionImportUpload/Install()` → `PackageImportService` (§11); `actionUpdatePackage/InstallFromRepository/Reinstall/Repair()` → `MarketplaceService`; permission-gated (`manageMarketplace`) |
| Publishing | `PackagePublisherController` | `actionPublish()` → `PackagePublisherService::publish()` (§30); `actionCreateVersion()` → `VersionManagerService::createVersion()`; granular permission-gated |
| Craft Resource Import wizard | `ResourceImportController` | Every "Import Existing X" + Sync From Source action (§12-14, §20) + `actionListFrontendFileCandidates()` (§18-19); **every action Dev-Mode-gated** |
| Shared Resources | `SharedResourceController` | `SharedResourceRegistryService`/`SharedResourceUsageService` (§28) |
| Commerce & Licensing (9 tabs) | `CommerceController` | Every commerce business service (§29); granular permission-gated |
| Settings | `SettingsController` | `actionSave()` requires Craft admin |
| First-run setup | `SetupController` | Creates the `site7Components` Matrix field |
| Fresh-Install Wizard | `InstallWizardController` | `InstallationPlanner`/`InstallationValidator`, pushes `InstallStarterKitJob` to the queue (never runs synchronously) (§15) |
| Update/Sync Wizard | `UpdateWizardController` | `SynchronizationPlanner`/`SynchronizationValidator`, pushes `SyncStarterKitJob` (§15) |
| Save as Template / Create from Template | `TemplateGeneratorController` | `TemplateGeneratorService`/`TemplateInsertionService`; generally available, not Dev-Mode-gated |
| Generate/Install Starter Kit | `StarterKitGeneratorController` | `actionGetEntries`/`actionSaveAsStarterKit` Dev-Mode-gated; `actionInstall` is not |

**Console controllers**: `PackageController` (Matrix sync), `InstallController`/`UpdateController` (CLI presentation over §15's services, including the actual subprocess-stage entry points `actionRunStage`/`actionApplyRemovals` — never user-invoked directly), `MakeController` (`actionStarterKit`, `actionPackage` scaffolding, `actionSetupMatrixField`, `actionRelinkMatrix`), `ClearController` (settings reset).

**Access model**: three independent gates, never conflated — `Site7Studio::isDevMode()` (Import wizard, Package Authoring, Delete/Detach), granular CP permissions registered by `CpSubscriber` (Commerce, Publishing, Marketplace), and Craft's own `requireAdmin()` (Settings save only).

---

## 32. Events / Subscribers

**Event system architecture**: a thin, custom facade (`events/EventDispatcher.php`) over Yii's real, static `yii\base\Event::on()`/`::trigger()` — not an independent pub/sub bus. `EventDispatcher::dispatch($event)` calls `Event::trigger(get_class($event), $event->getEventName(), $event)`; because `BaseEvent::getEventName()` defaults to `static::class`, both arguments end up being the event's own class name. Genuine Craft-core events (e.g. `UserPermissions::EVENT_REGISTER_PERMISSIONS`) are subscribed the conventional way, directly, bypassing this dispatcher entirely.

**Subscribers actually wired up** (only two exist):

| Subscriber | Registered by | Listens to | Effect |
|---|---|---|---|
| `CpSubscriber` | `CpServiceProvider` | `UserPermissions::EVENT_REGISTER_PERMISSIONS`, `RegisterNavigationEvent`, `RegisterPermissionsEvent`, `Dashboard::EVENT_REGISTER_WIDGET_TYPES` | Pure CP wiring — nav item, permissions, `LibraryWidget` registration. No file/DB side effects. |
| `PackageBackupSubscriber` | `ImportServiceProvider` | `ResourceImportedEvent` | **Side effect**: `PackageBackupService::backupToLocalRepository()` for every imported package handle — see §27. |

`ResourceImportedEvent` is dispatched by every "Import Existing X" service (§12-14) — this is the mechanism by which every import automatically gets an initial, real, restorable version archive (§12's lifecycle diagram shows exactly where this fires).

Other event classes exist as extension points (`events/commerce/*`, `events/publishing/*` — `VersionCreatedEvent`, `PackageBuiltEvent`, `BeforePublishEvent`/`AfterPublishEvent`, license/subscription/plan-change events) with no subscriber currently attached — confirmed by direct search; do not assume a side effect exists for these without checking again at the time of use.

---

## 33. Services Map

Only architecturally significant services — trivial helpers omitted per the task's own scope guidance. "Files it modifies" lists filesystem paths; "tables" lists DB tables.

| Service | Responsibility | Key methods | Modifies (files) | Modifies (tables) |
|---|---|---|---|---|
| `PackageManagerService` | Package registry, install/enable/disable/remove/delete, installed-file orchestration | `installPackage`, `enablePackage`, `updateInstalledFiles`, `deletePackage` | `packages/{handle}/`, `templates/_blocks/*.twig`, owned-file targets | `site7_packages`, `site7_installed_files` |
| `CraftResourceService` | Craft Field/Entry Type creation, template copy, resource removal | `generateResources`, `removePackageResources` | `templates/_blocks/*.twig` | (Craft's own field/entrytype tables, via Craft APIs) |
| `MatrixEntryTypeImportService` | Import Existing Section (single Entry Type) | `importFromEntryType`, `captureOwnedFiles`, `syncOwnedFilesFromLiveSource`, `copyTemplateTwigFromLiveSource` | `packages/{handle}/*` | `site7_packages`, `site7_section_import_sources` |
| `SectionUpdateService` | Sync From Source for Section packages | `diff`, `updateInPlace` | `packages/{handle}/fields.yaml,matrix.yaml,template.twig,frontend/*` | (via `VersionManagerService`) |
| `VersionManagerService` | Semver bump + immutable version recording | `createVersion`, `getVersionHistory` | `packages/{handle}/manifest.json`, new `.s7pkg` | `site7_package_versions` |
| `PackageExportService` | Build `.s7pkg` archives | `exportPackage`, `resolveDependencyClosure` | `storage/site7-studio/exports/*.s7pkg` | `site7_package_versions`, `site7_package_dependencies` (via `MarketplaceService`) |
| `PackageImportService` | Import a `.s7pkg` | `validatePackage`, `importPackage` | `packages/{handle}/*` (wholesale replace) | `site7_packages`, `site7_package_versions`, `site7_package_dependencies` |
| `PackageArchiveHelper` | Stateless zip/checksum primitives | `computeDirectoryChecksum`, `computeFileChecksum`, `extractZip`, `readEntry`, `replaceDirectory`, `addDirectoryToZip` | (generic, path-parameterized) | none |
| `MarketplaceService` | Repository registry, update-check, reinstall/repair, version recording | `recordVersion`, `checkForUpdates`, `installFromRepository`, `reinstallPackage` | (delegates) | `site7_package_versions`, `site7_package_dependencies` |
| `InstalledFileBaselineService` | Per-file baseline persistence | `record`, `getBaseline`, `allForPackage`, `remove` | none | `site7_installed_files` |
| `PackageUpdatePlanner` | Baseline/live/incoming three-way classification | `plan`, `classify`, `resolveIncomingChecksums`, `resolveArchiveEntryName` | none (read-only) | none (read-only) |
| `PackageRollbackService` | Restore a package to a historical version | `rollback` | `packages/{handle}/*` (wholesale), installed files (via `updateInstalledFiles`) | none directly (delegates to `PackageManagerService`/`InstalledFileBaselineService`) |
| `PackageBackupService` | Auto-backup on create/import | `backupToLocalRepository` | `storage/site7-studio/marketplace-repo/*.s7pkg` | `site7_package_versions` (repoints `archivePath`) |
| `PackageAuthoringService` | Manual package create/edit | `createPackage`, `updatePackage`, `saveSectionFields` | `packages/{handle}/manifest.json` | `site7_packages` |
| `DependencyResolverService` | Shared Resource dependency resolution | `resolvePackage`, `resolveSharedResources` | none | none (reads `site7_shared_resources`) |
| `SharedResourceRegistryService` | Shared Resource registry | `registerIfMissing`, `registerField`, `getDependentPackages` | none | `site7_shared_resources`, `site7_shared_resource_dependencies` |
| `PackageBuilderService` / `PublishValidatorService` / `RepositoryManagerService` / `PublishHistoryService` | Publishing pipeline (§30) | `build`, `validatePackage`, `getTarget`, `recordPublish` | `packages/{handle}/package.json,CHANGELOG.md,LICENSE.md` | `site7_package_publications` |
| `CommerceClient` + commerce services | Commerce24 HTTP integration (§29) | `request`, `activate`, `getCurrentPlan`, `isEntitled` | `storage/site7-studio/commerce24-cache/*` | none (remote state) |
| `InstallationPlanner`/`Validator`/`Executor` + executors | Starter Kit installation (§15-16) | `plan`, `validateInstallation`, `execute` | plugin/composer/npm/Craft-resource state, `templates/`/`frontend/` | `site7_install_sessions`, `site7_installed_starter_kits` |
| `SynchronizationPlanner`/`Validator`/`OrchestratorService` | Starter Kit sync (§15) | `plan`, `validateSynchronization`, `execute` | (via reused installer) | `site7_sync_history`, `site7_sync_sessions`, `site7_installed_starter_kits` |

---

## 34. Error Handling / Validation

Four validation layers, each scoped to one pipeline stage — never shared:

| Layer | Class | Gates | Blocking? |
|---|---|---|---|
| Pre-write (import) | `ResourceImportValidator` | Classification-driven — nothing capturable at all is the only hard error; handle collisions/non-semver versions are auto-corrected warnings | Only "nothing capturable" blocks |
| Install/discovery | `PackageValidator` (`services/engine/`) | Manifest structure, required files present for the type | Yes |
| Publish-readiness | `PublishValidatorService` | Hard errors (manifest/dependency closure) + a 0-100 quality score | Hard errors only |
| Starter Kit install | `InstallationValidator` | Blueprint integrity, Composer/npm/PHP-version availability | Yes (dry-run only, never executes) |
| Starter Kit sync | `SynchronizationValidator` | Live Craft version, plugin version constraints, Project Config drift | Yes (dry-run only) |

**Manifest validation**: `PackageManifest::validate()` (Yii/Craft `Model` validation, `defineRules()`) — `required` on `type`/`handle`/`name`/`version`/`schemaVersion` only; everything else `'safe'` (§8's backward-compatibility guarantee depends on this).

**Archive/checksum validation**: `PackageImportService::validatePackage()` recomputes `PackageArchiveHelper::computeDirectoryChecksum()` on the extracted archive and compares against `bundle-manifest.json`'s recorded checksum per bundled package — a mismatch is a hard error ("archive may be corrupted or altered").

**Conflict reporting**: never an exception — `PackageUpdatePlanner`/`SynchronizationPlanner` return structured result objects (`{result, message, ...}` / `Conflict` models) that the caller (controller or console command) decides how to surface. A conflict is a **first-class return value**, not an error condition.

---

## 35. Testing Architecture

**Runner**: Codeception 5 (`codeception.yml` + `tests/unit.suite.yml`, actor `UnitTester`, only the `Asserts` module enabled — no test currently calls `$this->tester->...`, so no DB/App Codeception module is configured). Run via `php vendor/bin/codecept run unit <path> -c plugins/site7-studio/codeception.yml` from the Craft root (the plugin has no local `vendor/`; it's symlinked into the root project's `vendor/site7/studio`).

> **Setup history worth knowing**: as of the start of Step 3 (this document's authoring period), **neither PHPUnit nor Codeception were actually installed** in this project (only present as an uninstalled transitive `require-dev` of `craftcms/cms`) — every one of the ~30 pre-existing test files had **never once been executed**. Both were installed as explicit dev dependencies during Step 3, and `codeception.yml`/`tests/unit.suite.yml`/the generated `UnitTester` actor were added at that time. Confirm this is still current before assuming a green CI run exists anywhere — none does, as of this writing; tests are run manually.

**Test types**:
- **Pure unit tests** (`tests/unit/**`) — no live Craft app/DB, run in isolation. Covers: `PackageArchiveHelper`, `PackageUpdatePlanner` (`classify()` + `resolveArchiveEntryName()`), `PackageManifest` (backward compatibility, `ownedFiles`), `FrontendToolingScanner`, `ResourceClassifierService`, `ResourceImportValidator`, `CraftResourceDiscoveryService`, `DependencyAnalyzer`, `SynchronizationPlanner`, installation planner/executor/stage-runner logic, `ResourceGraph`, `NavigationScanner`, `RelationFieldSourceResolver`, `LibraryService`, `ManifestReader`, `PlatformConfigService`, `SearchService`.
- **Integration tests** (`tests/integration/**`, extend PHPUnit `TestCase` directly, not Codeception `Unit`) — `CpSubscriberTest`.
- **Live DDEV verification** (not committed as test files — throwaway scripts, always fully self-cleaning) — the primary verification method for anything touching Craft's live database/filesystem (installation, sync, update, rollback). Pattern: create real temporary Fields/Entry Types/`_blocks/` files via a bootstrapped console script, exercise the real service call, assert, then delete every trace (DB rows via direct SQL where Craft's own service methods proved unreliable outside a normal request lifecycle — see the known issue below). This is the project's established convention for anything a pure unit test can't safely cover (documented in project memory as "any phase that writes real files/DB rows should get a live browser/console test before being reported done — `php -l`/unit tests alone did not catch the real bugs").

**Fixtures**: `tests/fixtures/packages/test-hero/` — a complete, minimal, real Section package (`manifest.json`, `fields.yaml`, `matrix.yaml`, `template.twig`, `preview/`) used as an example structure; never scanned by production package discovery.

**Known pre-existing, unrelated test failures — do not fix these as a side effect of unrelated work** (confirmed present, not caused by any Step 2–8.2 change):
1. `tests/unit/models/packages/PackageManifestTest.php` — the two original tests (`testLoadsLegacyManifestWithoutNewFields`, `testRoundTripsNewSchemaFields`) fail with `Error: Class "Yii" not found` because they call `->validate()`, which needs a live Yii/Craft app this suite has never had. (The two new Step 8.1 tests added for `ownedFiles` deliberately avoid calling `->validate()` and pass cleanly.)
2. `tests/unit/services/synchronization/SynchronizationPlannerTest.php` — several tests fail the same way (`Class "Craft" not found`), same root cause.
3. Three test files have a literal typo, `protected clone $tester;` instead of `protected \UnitTester $tester;` — a hard PHP parse error: `LibraryServiceTest.php`, `ManifestReaderTest.php`, `SearchServiceTest.php`. Never fixed (out of scope of every step that encountered it).
4. `ResourceClassifierServiceTest`/`ResourceImportValidatorTest` — 3 genuine pre-existing assertion-value mismatches (expected vs. actual), unrelated to any documented feature work.

**Currently passing** (verified at time of last major change, Step 8.2): `PackageArchiveHelperTest` (5/5), `PackageUpdatePlannerTest` (14/14 — 10 original + 4 added for the Step 8.2 resolver), the two new `PackageManifestTest` `ownedFiles` cases.

---

## 36. Security / Safety Model

- **Archive validation**: checksum-verified on import (§34); a tampered/corrupted `.s7pkg` is rejected before anything is written to disk.
- **Path safety**: `MatrixEntryTypeImportService::captureOwnedFiles()` explicitly rejects any `sourcePath`/selected candidate containing `..` or a leading `/` (path-traversal guard) before ever touching the filesystem — the one place in the codebase that accepts a caller-supplied relative path and must defend against it.
- **File replacement protection**: the content-compare guard in `CraftResourceService::generateResources()`/`removePackageResources()` (§17) — never overwrites/deletes a target file whose content doesn't match what this package itself is expected to have written.
- **Local modification protection**: the baseline/live/incoming three-way system (§23-24) — the single, general mechanism underlying every "don't destroy developer work" guarantee in this document.
- **Immutable historical versions**: §22 — structural, not policy (no code path exists to violate it).
- **Rollback protection**: §25 — identical three-way protection as forward updates, plus the narrow "already matches" reconciliation, implemented so it cannot weaken the general rule for any other caller.
- **Dependency validation**: missing/dead Shared Resources degrade to warnings, never block install (§28) — a deliberate availability-over-strictness choice, not a gap.
- **Permission boundaries**: three independent gates (§31) — Dev Mode, granular CP permissions, Craft admin — applied per-action, not per-controller uniformly.
- **Entitlement enforcement**: fail-closed for `FeatureGateService` (§29) when no plan resolves.

---

## 37. Complete Package Lifecycle

```
CREATE (PackageAuthoringService::createPackage)          or   IMPORT (§12-14, real Twig/fields captured)
   ↓                                                                 ↓
   └──────────────────────────┬──────────────────────────────────────┘
                               ↓
   Backup (PackageBackupSubscriber → PackageBackupService, automatic)
                               ↓
   First real VERSION (v1) — real archive, real checksum (§21-22)
                               ↓
   BUILD (PackageBuilderService — adds package.json/CHANGELOG/LICENSE, §30)  [optional, publish-only]
                               ↓
   EXPORT (PackageExportService — the same archive mechanism every version uses, §10)
                               ↓
   PUBLISH (PackagePublisherService → a repository target, Local or Commerce24, §29-30)  [optional]
                               ↓
   INSTALL (PackageManagerService::installPackage → CraftResourceService, §16)
                               ↓
   BASELINE recorded (InstalledFileBaselineService, per installed/owned file, §23)
                               ↓
   [DEVELOP — developer edits templates/_blocks/{handle}.twig and/or owned frontend files directly]
                               ↓
   SYNC (SectionUpdateService — no-op if unchanged; else re-captures + creates ONE new version, §20)
                               ↓
   VERSION 2, 3, ... (each immutable, each with its own real archive, §21-22)
                               ↓
   UPDATE (PackageManagerService::updateInstalledFiles — three-way safe/conflict, §24)
                               ↓
   CONFLICT HANDLING (locally-modified files reported, never overwritten, §24)
                               ↓
   ROLLBACK (PackageRollbackService — to any historical version, same three-way protection, §25)  [as needed]
                               ↓
   UNINSTALL (soft, resources preserved) or DELETE (permanent, usage-checked) or DETACH (undo-import), §26
```

Every arrow above is a real, verified (unit-tested and/or live-DDEV-verified across Steps 2–8.2) code path — this diagram is not aspirational.

---

## 38. Architecture Invariants — DO NOT BREAK THESE

Every rule below was verified against current code while writing this document (not carried forward from an older plan).

1. **The runtime template is `templates/_blocks/{handle}.twig`. Never create or use `templates/site7-components/` as a rendering path.** (`CraftResourceService::generateResources()`/`removePackageResources()`, §17.)
2. **Do not create a second rendering system.** Owned files, when they exist, install to their real, mirrored path on the host site — never into a Site7-specific runtime tree.
3. **Historical package archives (`.s7pkg`) are immutable once written.** No code rewrites or deletes an existing archive file. (§22.)
4. **Existing `site7_package_versions` rows are never modified after creation** (except `PackageBackupService` repointing `archivePath` to the *same bytes*' new location, §27).
5. **Do not silently overwrite a locally modified installed file.** Every write to `templates/_blocks/` or an owned-file target goes through the baseline/live/incoming three-way check (§24), or the narrower install-time content-compare guard (§17) — never a blind copy.
6. **Baseline (`site7_installed_files`) is conceptually different from a package's own content checksum.** One describes the host site's disk state at install time; the other describes the package's own version. Do not conflate them or read one where the other is needed (§23).
7. **Multiple changes (fields, Twig, N owned files) in one Sync From Source call produce exactly ONE new package version, never one per changed thing.** (§20, verified live.)
8. **Existing packages without `ownedFiles` behave exactly as before Step 8.1 — `ownedFiles = []` is a true no-op at every lifecycle stage.** (§18.)
9. **Frontend file ownership is always explicit. Never guess ownership from a filename or path convention.** (§18-19 — the real host site's CSS/JS is organized by UI role, not per-component, so filename-matching would be actively wrong, not just unnecessary.)
10. **Rollback never creates a new version and never modifies a historical version record or archive.** (§25.)
11. **Do not bypass `PackageExportService`/`MarketplaceService::recordVersion()` to create a version.** There is exactly one version-recording path; hand-building a `PackageVersionRecord` was the literal Step 4 bug. (§21.)
12. **Do not create a second checksum implementation.** `PackageArchiveHelper::computeDirectoryChecksum()`/`computeFileChecksum()` are the only two, and every other checksum need in this codebase calls one of them.
13. **Do not create a second baseline table.** `site7_installed_files`/`InstalledFileBaselineService` is generic over any file type (proven by Step 8.2 adding owned-file support with zero schema change) — extend it, don't duplicate it. (§23.)
14. **Do not bypass `PackageUpdatePlanner` for update/conflict decisions.** `classify()` is the one tested decision table; do not hand-roll a second comparison anywhere a file is written to an installed site. (§24.)
15. **`PackageUpdatePlanner`/`InstalledFileBaselineService` (file-level) and `SynchronizationPlanner`/`InstalledStarterKitTrackingService` (Starter-Kit-resource-level) are deliberately two separate systems implementing the same *pattern* at two granularities.** Do not merge them without a very strong, explicitly-approved reason — both docblocks state the other is "out of scope." (§15, §24.)
16. **Never call `PackageBackupService` from a code path that might already hold multiple independent version archives** (update/rollback) — its latest-only retention can delete an older version's own archive. (§24, §27 — a real bug found and fixed during Step 6.)
17. **`packages/{handle}/` is Site7-owned storage, safe to overwrite wholesale during import/sync/rollback. The host site's installed files (`_blocks/`, owned-file targets) are developer-editable and must go through the safety checks above.** Confusing these two is the most common category of mistake in this codebase's own history. (§7, §17.)

---

## 39. Common Bug / Issue-Fixing Guide

**"Template does not render / a component shows nothing"**
1. Confirm `templates/_blocks/{handle}.twig` actually exists on disk and has content (`CraftResourceService::generateResources()`, §16-17).
2. Confirm the Matrix block's Entry Type `handle` exactly matches the `_blocks/*.twig` filename (`templates/_includes/matrix-container.twig`'s dispatch is filename-based).
3. Confirm the package is `enabled` (installed alone is not enough — `enablePackage()` must have run to link into the Site7 Matrix field).
4. Check `site7_installed_files` for a baseline row at that `targetPath` — if missing, install may have skipped the copy (check the content-compare guard, §17, for a "skipped to avoid overwriting" warning in `PackageManagerService::$_lastInstallWarnings`).
5. Confirm the package's current `version` (`PackageRecord::version`) matches what you expect — a stale install may be running an older Twig than the package source currently has.

**"Package update overwrote developer changes"** — should be structurally impossible; if it happened, this is a real bug:
1. Check the `site7_installed_files` baseline row for that file — was it accurate at the time of update?
2. Recompute `PackageArchiveHelper::computeFileChecksum()` on the live file and compare to the baseline manually.
3. Check `PackageUpdatePlanner::resolveIncomingChecksums()`/`resolveArchiveEntryName()` — did it resolve the correct archive entry for the target version?
4. Check `PackageManagerService::applySafeFileUpdate()` — did it actually verify the written checksum before advancing the baseline, or was that check bypassed?
5. Re-read §24's invariant #5/#14 — any fix here must go through `PackageUpdatePlanner`, never around it.

**"Sync created a duplicate version" / "Sync created a version when nothing changed"**
1. Check `SectionUpdateService::diff()`'s three sub-diffs (`fieldsDiff`, `twigDiff`, `ownedFilesDiff`) — is one of them incorrectly reporting `changed: true`?
2. Check `VersionManagerService::createVersion()`'s bump-base computation (`resolveBumpBaseVersion()`) — did it collide with an existing version string (possible after a rollback if the fix in §21 point 2 were ever reverted)?
3. Check `MarketplaceService::recordVersion()`'s dedup guard — is it being bypassed by a direct `PackageVersionRecord` insert somewhere (invariant #11 violation)?
4. Check `PackageExportService::exportPackage()`'s checksum computation on the exact directory state at the moment of the call.

**"Rollback didn't restore anything" / "Rollback reports conflict on everything"**
1. Confirm the target `PackageVersionRecord` actually has a non-null, existing `archivePath` (`PackageRollbackService::rollback()` throws loudly if not — check for that exception, don't assume a silent no-op).
2. Check whether every tracked file's baseline genuinely diverged from both live and the target — if the site was never actually updated to match its own baseline (a stale/incorrect baseline row), everything will legitimately show as a conflict. Verify the baseline against the file's actual install-time content.

**"A new file type won't sync/update/rollback correctly"**
1. Confirm it's declared as an `ownedFiles` entry with correct `sourcePath`/`targetPath` (§18) — anything not in `ownedFiles` and not the built-in template mapping is invisible to the whole system by design (§38 invariant #9).
2. Check `PackageUpdatePlanner::resolveArchiveEntryName()` — does the built-in regex (`^templates/_blocks/[^/]+\.twig$`) accidentally match or fail to match your new path shape?

---

## 40. How to Safely Modify Site7 Studio

1. Read the relevant section of this document (§1's own rule).
2. Search for the existing service that already owns this responsibility (§33) — reuse it.
3. Search for every caller of the method you're about to change (do not assume there's only one).
4. Search for every database record/table the change touches (§6, §45) — confirm cascade/FK behavior won't surprise you.
5. Search for events dispatched/subscribed around this code path (§32) — a side effect may be triggered somewhere non-obvious (e.g. `PackageBackupSubscriber` firing on any `ResourceImportedEvent`).
6. Search existing tests for this class (§35) — run them before changing anything, so you know your starting baseline.
7. Audit current behavior by actually reading the code, not by trusting an older doc (§1's primary rule) — this document itself should be treated the same way for anything not re-verified recently.
8. Make the smallest change that satisfies the requirement — do not refactor unrelated code in the same change.
9. Test the isolated behavior (unit test if the code is Craft-independent; otherwise proceed to 11).
10. Run the regression suite for anything you touched (§35).
11. If the change touches install/sync/update/rollback or anything writing to a live Craft site, write a throwaway, fully-self-cleaning live DDEV verification script (the established pattern, §35) — do not claim success from unit tests alone for lifecycle-affecting code.
12. Check `git status`/`git diff` confirms production `templates/`/`frontend/` files are untouched, unless the task explicitly requires changing them.
13. Clean up every temporary fixture (Fields, Entry Types, package directories, DB rows, archive files) — confirm via direct DB queries, not just "the script printed cleanup complete."
14. Review the full `git diff` before staging.
15. Commit with a message that states what changed and, if relevant, update this document's affected section in the same change.

---

## 41. Extension Guide for New Features

| Adding... | Layers that normally change | Layers that should NOT need to change |
|---|---|---|
| A new package resource type (beyond Section/Template/Pattern/Starter-Kit/Theme) | `models/packages/` (new `Package` subclass), `PackageManifest` (new optional fields, §8's pattern), `PackageManagerService::installPackage()`'s type-specific cascade | `PackageArchiveHelper`, `VersionManagerService`, `PackageUpdatePlanner` — all already generic |
| A new package-owned file type (beyond frontend-css/js) | `PackageManifest::$ownedFiles`'s `type` value (just a new string, no schema change), any CP UI presenting it | `PackageUpdatePlanner`, `InstalledFileBaselineService`, `PackageManagerService::installOwnedFiles()` — all already type-agnostic (§18) |
| A new import source (beyond Section/Page/Website) | A new `services/import/*ImportService.php`, a new `*SourceRepository`+migration (mirror `SectionImportSourceRepository`'s shape exactly), a `ResourceImportController` action | `PackageManagerService`, `VersionManagerService`, the whole update/rollback system — an import service's only job is producing a valid `packages/{handle}/` directory + calling `discoverPackages()`/`installPackage()` |
| New version behavior (e.g. a new bump rule) | `VersionManagerService::bumpVersion()`/the calling service's bump-type decision (§20-21) | `PackageExportService`, `MarketplaceService::recordVersion()` — the recording mechanism itself never changes per bump-rule change |
| A new Marketplace integration (beyond Local/Commerce24) | A new class implementing `MarketplaceRepositoryInterface`/`PackagePublishTargetInterface`, registered in `MarketplaceService::init()`/`RepositoryManagerService::init()` | Everything else — both interfaces exist exactly so a third implementation requires zero changes elsewhere (§29) |
| A new native Craft resource type in the Starter Kit system | A new scanner in `services/scanning/`, a new executor in `services/installation/executors/`, `SynchronizationPlanner::resourceKinds()` | `InstallationPlanner`/`InstallationExecutor`'s orchestration (type-agnostic dispatch already), `SynchronizationOrchestratorService` |
| New frontend tooling support (build systems) | `FrontendToolingScanner::SYSTEM_CONFIG_FILES`/`ADDITIONAL_CONFIG_FILES` constants only | Everything downstream — detection output shape is already generic (§19) |
| A new CP screen | A new controller (+ dedicated route in `Site7Studio::attachEventHandlers()` if it needs a clean URL), reusing existing services — **do not put business logic in a controller** | Services should never need to change shape just to gain a new caller |

---

## 42. Future Product Website / Public Documentation

This document is **internal developer documentation** — it references file paths, class names, and implementation history that should never be published externally as-is. A future product website/public docs portal should be built as a **separate, derived artifact**, not by exposing this file.

**INTERNAL DEVELOPER DOCUMENTATION** (this document, `PHASE-*.md`, `VALIDATION-REPORT-FULL-PIPELINE.md`, `PACKAGE-SAFETY-AND-BACKUP.md`): implementation detail, class/method names, discovered bugs, internal invariants, troubleshooting by code path. **Never publish verbatim.**

**PUBLIC PRODUCT DOCUMENTATION** — concepts that CAN become public content, translated away from implementation detail:
| Internal concept (this doc) | Public form |
|---|---|
| §2 Product Overview | Marketing "What is Site7 Studio" page |
| §37 Complete Package Lifecycle | A simplified lifecycle diagram — "Create → Version → Install → Update → Rollback" without service/class names |
| §17 Template File Lifecycle | A "How component updates protect your customizations" feature page/FAQ |
| §24 Update/Conflict System | A "Safe Updates" feature page — the baseline/live/incoming *concept*, not the `PackageUpdatePlanner` class |
| §18-19 Owned Files | A "What gets versioned" user guide — explained as "you choose what belongs to a component," not manifest JSON schema |
| §29 Marketplace/Commerce | Marketplace documentation, pricing/plan pages, licensing FAQ |
| §31 Controllers/CP UI | A user guide walking through each actual CP screen (screenshots, not controller names) |
| §39 Troubleshooting Guide | A public FAQ/troubleshooting page, rewritten in user-facing language (no class names, no code paths) |

**Do NOT expose automatically**: exact file paths, class/method names, database schema, discovered-bug write-ups (§15's subprocess-architecture bugs, §24's backup-service footgun), internal migration history, `AI DEVELOPMENT NOTE` callouts, permission/entitlement implementation detail beyond "here's what your plan includes."

---

## 43. Current Limitations / Deferred Work

Labeled per your instruction — **IMPLEMENTED** / **PARTIALLY IMPLEMENTED** / **DEFERRED** / **NOT IMPLEMENTED**. Nothing below is presented as done unless directly verified against current code or an explicit live test in this project's history.

**IMPLEMENTED, verified live**: Package Engine core (create/import/export/import/version/install/enable/disable/delete/detach), Sync From Source (fields+Twig+owned files, single-version-per-sync rule), immutable versioning+archives, installed-file baseline, three-way update/conflict system, rollback (with the "already matches" refinement), owned-files full lifecycle (Step 8.1+8.2), Shared Resource dependency resolution, Commerce24 client + entitlement enforcement (contingent on a real reachable server, §29), publishing pipeline (validate/build/publish/history).

**PARTIALLY IMPLEMENTED**:
- Starter Kit whole-site provisioning (§15) — the architecture (plan/validate/execute, subprocess-per-stage, baseline/live/new sync) is sound and repeatedly validated in isolation, but `VALIDATION-REPORT-FULL-PIPELINE.md`'s full real-data run found several capture-side gaps, some fixed in later passes, some not:
  - **Fixed** (per documented follow-up passes, not independently re-verified as part of *this* document's authoring): the `blueprint.json`-generation wiring gap, the fresh-`plugin/install` migration-skip bug, `PackageRepository::save()` schema drift, per-page `ProjectConfig::rebuild()` non-determinism, native-content Section/Entry-Type structural capture (a third fix pass, including a `saveEntryType()` `hasTitleField` bug).
  - **Not fixed**: `TemplateGeneratorService::generateFromEntry()` only works for pages authored through Site7 Studio's own visual builder (0/885 real traditionally-authored entries qualified in the validation run); Category/Tag field **values** (not group settings) are never captured/re-linked; blank-title entries fail capture with an unhelpful error; no capture path for raw asset files/SCSS/Twig layouts-partials-macros/a distinct demo-content concept; the Craft queue does not auto-run during the Install/Update Wizard's polling flow (`craft queue/run` must be triggered manually).
- `PackageDependencyRecord.minimumVersion` — column exists, not enforced by any resolver (§28).
- Package deletion (`deletePackage()`) never deletes the underlying `.s7pkg` archive files from disk, only DB rows (§26) — a known, accepted storage-growth characteristic, not scheduled for a fix.

**DEFERRED** (explicitly scoped out of the steps that built the adjacent system, not forgotten):
- Frontend CSS/JS **build-output** packaging (compiled `web/themes/front/`) — deliberately never packaged (§10, §19); source-only by design.
- Package **signing** (`NullPackageSigner`) — architecture/extension point only, no cryptography (§29).
- Any CP/controller UI specifically for triggering owned-file update/rollback — the underlying services are complete and directly verified (§18, §24-25), but no dedicated screen calls them yet.
- Circular dependency **detection** for the package→package `requires` graph (exists for the whole-project native-resource graph via `DependencyAnalyzer::analyzeCycles()`, §15, but not for `requires`, §28).

**NOT IMPLEMENTED**: rename/move detection for owned files (a renamed-but-content-identical file is indistinguishable from "old path removed + new path freshly added" to the path-keyed baseline system — flagged as a known, unaddressed edge case, not silently assumed solved); automatic ownership conflict detection if two packages ever declared the same `targetPath` (nothing currently prevents or flags this).

---

## 44. Appendix — Class / Service Index

Organized by subsystem. Path is relative to `plugins/site7-studio/src/`.

**Plugin core**: `Site7Studio.php` · `base/PluginTrait.php`

**Package Engine**: `services/PackageManagerService.php` · `services/CraftResourceService.php` · `services/engine/PackageReader.php` · `services/engine/PackageDiscovery.php` · `models/packages/Package.php` (+`SectionPackage`/`TemplatePackage`/`PatternPackage`/`StarterKitPackage`/`ThemePackage`) · `models/packages/PackageManifest.php` · `records/PackageRecord.php` · `records/ComponentRecord.php` · `records/TemplateRecord.php` · `records/PackageDependencyRecord.php` · `records/PackageVersionRecord.php` · `records/PackageInstalledFileRecord.php` · `repositories/PackageRepository.php`

**Import**: `services/import/MatrixEntryTypeImportService.php` · `services/import/CraftSectionImportService.php` · `services/import/PageImportService.php` · `services/import/WebsiteImportService.php` · `services/import/SectionUpdateService.php` · `services/import/PageUpdateService.php` · `services/import/ResourceClassifierService.php` · `services/import/CraftResourceDiscoveryService.php` · `services/import/ResourceAnalyzerService.php` · `services/import/ResourceImportValidator.php` · `services/import/EntryTypeSourceHasher.php` · `services/import/EntrySourceHasher.php` · `services/import/WebsiteTreeService.php` · `services/import/StarterKitReferenceResolverService.php` · `services/import/StarterKitSyncService.php` · `records/SectionImportSourceRecord.php` · `records/PageImportSourceRecord.php` · `records/WebsiteImportSourceRecord.php` · `repositories/SectionImportSourceRepository.php` · `repositories/PageImportSourceRepository.php` · `repositories/WebsiteImportSourceRepository.php`

**Version/Archive/Update/Rollback**: `services/publishing/VersionManagerService.php` · `services/PackageExportService.php` · `services/PackageImportService.php` · `services/support/PackageArchiveHelper.php` · `services/MarketplaceService.php` · `services/synchronization/InstalledFileBaselineService.php` · `services/synchronization/PackageUpdatePlanner.php` · `services/publishing/PackageRollbackService.php` · `services/support/PackageBackupService.php` · `events/subscribers/PackageBackupSubscriber.php`

**Owned Files (Step 8.1/8.2)**: `models/packages/PackageManifest.php` (`$ownedFiles`) · `services/import/MatrixEntryTypeImportService.php` (`captureOwnedFiles`, `syncOwnedFilesFromLiveSource`) · `services/FrontendToolingScanner.php` (`listCandidateFrontendFiles`) · `services/PackageManagerService.php` (`installOwnedFiles`) · `services/synchronization/PackageUpdatePlanner.php` (`resolveArchiveEntryName`)

**Authoring/Publishing**: `services/PackageAuthoringService.php` · `services/publishing/PackageBuilderService.php` · `services/publishing/PublishValidatorService.php` · `services/publishing/RepositoryManagerService.php` · `services/publishing/PublishHistoryService.php` · `services/publishing/NullPackageSigner.php` · `services/publishing/PackagePublisherService.php` · `records/PackagePublicationRecord.php` · `interfaces/PackageBuilderInterface.php` · `interfaces/PackagePublisherInterface.php` · `interfaces/PackagePublishTargetInterface.php` · `interfaces/PackageSignerInterface.php`

**Dependencies/Shared Resources**: `services/DependencyResolverService.php` · `services/SharedResourceRegistryService.php` · `services/SharedResourceUsageService.php` · `records/SharedResourceRecord.php` · `records/SharedResourceDependencyRecord.php`

**Starter Kit System**: `services/ProjectBuilder.php` · `services/DependencyAnalyzer.php` · `services/BlueprintBuilder.php` · `services/StarterKitBuilder.php` · `services/StarterKitGeneratorService.php` · `models/registry/ResourceGraph.php` · `services/installation/InstallationPlanner.php` · `services/installation/InstallationValidator.php` · `services/installation/InstallationExecutor.php` · `services/installation/InstallationSessionService.php` · `services/installation/InstallationStageRunner.php` · `services/installation/InstallationOrchestratorService.php` · `services/installation/executors/*.php` (7 files) · `services/installation/StarterKitCatalogService.php` · `services/synchronization/SynchronizationPlanner.php` · `services/synchronization/SynchronizationValidator.php` · `services/synchronization/SynchronizationOrchestratorService.php` · `services/synchronization/SynchronizationSessionService.php` · `services/synchronization/SynchronizationHistoryService.php` · `services/synchronization/InstalledStarterKitTrackingService.php` · `services/synchronization/UpdateCatalogService.php` · `services/synchronization/ResourceRemovalExecutor.php` · `records/InstallationSessionRecord.php` · `records/InstalledStarterKitRecord.php` · `records/SynchronizationHistoryRecord.php` · `records/SynchronizationSessionRecord.php`

**Frontend/Composer scanning**: `services/FrontendToolingScanner.php` · `services/ComposerDependencyScanner.php` · `services/scanning/*.php`

**Marketplace/Commerce**: `services/commerce/CommerceClient.php` · `services/commerce/LicenseService.php` · `services/commerce/PlanService.php` · `services/commerce/SubscriptionService.php` · `services/commerce/PackageService.php` · `services/commerce/DownloadService.php` · `services/commerce/UpdateService.php` · `services/commerce/FeatureGateService.php` · `repositories/marketplace/LocalMarketplaceRepository.php` · `repositories/marketplace/Commerce24MarketplaceRepository.php` · `repositories/marketplace/LocalPublishTarget.php` · `repositories/marketplace/Commerce24PublishTarget.php` · `models/commerce/*.php` (`LicenseInfo`, `PlanInfo`, `CustomerInfo`, `SubscriptionInfo`, `CommerceApiException`)

**Controllers**: `controllers/*.php` (15 files, §31) · `console/controllers/*.php` (5 files, §31)

**Events**: `events/EventDispatcher.php` · `events/BaseEvent.php` · `events/*.php` · `events/commerce/*.php` · `events/publishing/*.php` · `events/subscribers/CpSubscriber.php` · `events/subscribers/PackageBackupSubscriber.php`

---

## 45. Appendix — Database Table Index

| Table | Purpose | Main owner service | Related records |
|---|---|---|---|
| `site7_packages` | Package registry | `PackageManagerService` | `PackageRecord` |
| `site7_components` | Section-package CP metadata | `PackageManagerService` (discovery) | `ComponentRecord` |
| `site7_templates` | Template-package CP metadata | `PackageManagerService` (discovery) | `TemplateRecord` |
| `site7_package_dependencies` | package→package + package→sharedResource edges | `MarketplaceService` | `PackageDependencyRecord` |
| `site7_package_versions` | Immutable version history | `MarketplaceService::recordVersion()` | `PackageVersionRecord` |
| `site7_package_publications` | Publish-attempt history | `PublishHistoryService` | `PackagePublicationRecord` |
| `site7_section_import_sources` | Import Existing Section provenance | `SectionImportSourceRepository` | `SectionImportSourceRecord` |
| `site7_page_import_sources` | Import Existing Page provenance | `PageImportSourceRepository` | `PageImportSourceRecord` |
| `site7_website_import_sources` | Import Existing Website provenance | `WebsiteImportSourceRepository` | `WebsiteImportSourceRecord` |
| `site7_installed_files` | Per-file installed-file baseline | `InstalledFileBaselineService` | `PackageInstalledFileRecord` |
| `site7_shared_resources` | Shared Resource registry | `SharedResourceRegistryService` | `SharedResourceRecord` |
| `site7_shared_resource_dependencies` | Shared→Shared edges | `SharedResourceRegistryService` | `SharedResourceDependencyRecord` |
| `site7_install_sessions` | Starter Kit install cross-process session | `InstallationSessionService` | `InstallationSessionRecord` |
| `site7_installed_starter_kits` | Whole-Blueprint sync baseline | `InstalledStarterKitTrackingService` | `InstalledStarterKitRecord` |
| `site7_sync_history` | Starter Kit sync attempt history | `SynchronizationHistoryService` | `SynchronizationHistoryRecord` |
| `site7_sync_sessions` | Starter Kit sync cross-process session | `SynchronizationSessionService` | `SynchronizationSessionRecord` |

Full column-level schema: §6. Every table confirmed directly against its migration file at the time of writing — no table listed here is inferred or assumed.

---

## 46. Appendix — File / Directory Ownership

| Category | Location | Owner | Who modifies it |
|---|---|---|---|
| Package source | `packages/{handle}/` | Site7 Studio (system-owned, §7) | Import services, `PackageAuthoringService`, `SectionUpdateService`/`PageUpdateService` (sync), `PackageImportService`/`PackageRollbackService` (wholesale replace) — never a human directly |
| Installed templates | `templates/_blocks/{handle}.twig` | **The developer/host site** (genuinely live-editable) | `CraftResourceService::generateResources()` (install/safe-update only, content-compare guarded) + the developer directly |
| Installed owned files | Mirrors the package's `targetPath` (e.g. `frontend/src/css/components/*.css`) | **The developer/host site** | `PackageManagerService::installOwnedFiles()`/`applySafeFileUpdate()` (guarded) + the developer directly |
| Version archives | `storage/site7-studio/exports/*.s7pkg` | Site7 Studio, immutable once written (§22) | `PackageExportService::exportPackage()` only |
| Backups | `storage/site7-studio/marketplace-repo/*.s7pkg` | Site7 Studio, latest-only per handle (§27) | `PackageBackupService::backupToLocalRepository()` only |
| Commerce24 download cache | `storage/site7-studio/commerce24-cache/*.s7pkg` | Site7 Studio | `Commerce24MarketplaceRepository::fetchPackage()` only |
| Cross-process session state | `storage/site7-studio/runtime/site7-studio/{install,update}/**` | Site7 Studio | `InstallationSessionService`/`SynchronizationSessionService`, and the subprocess-spawned stage/removal executors |
| Configuration | `config/project/*.yaml` | **Craft itself** | Only via `Craft::$app->getProjectConfig()->rebuild()` — Site7 Studio never hand-writes project-config YAML (§15's `ProjectConfigExecutor`) |
| Tests | `tests/**` | Site7 Studio developers | Manual, per §35 — no CI currently runs these automatically |
| Test fixtures | `tests/fixtures/packages/**` | Site7 Studio developers | Static, never scanned by production discovery |

---

*End of master document. Update the relevant numbered section in place when architecture changes — do not create a competing document for a subsystem already covered here.*
