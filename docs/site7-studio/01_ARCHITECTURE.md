# 01 — Architecture

## Layer diagram (current implemented architecture)

```
Craft CMS (Fields / Entries / Elements / Project Config APIs)
   ↓
Site7 Studio plugin  (Site7Studio extends craft\base\Plugin)
   ↓
Service Providers  (7 classes, each registers named components on the plugin's Yii service locator)
   ↓
┌───────────────────────────────────────────────────────────────────────┐
│ Domain layer                                                          │
│  Package Engine        → PackageManagerService, PackageReader,        │
│                           PackageDiscovery, records/*                  │
│  Import services        → MatrixEntryTypeImportService,                │
│                           CraftSectionImportService, PageImportService,│
│                           WebsiteImportService                         │
│  Sync services          → SectionUpdateService, PageUpdateService,     │
│                           StarterKitSyncService                        │
│  Version/Archive layer  → VersionManagerService, PackageExportService, │
│                           PackageImportService, PackageArchiveHelper,  │
│                           MarketplaceService                           │
│  Update/Conflict layer  → PackageUpdatePlanner,                        │
│                           InstalledFileBaselineService                 │
│  Rollback layer         → PackageRollbackService                       │
│  Craft resource layer   → CraftResourceService, CraftResourceRegistry, │
│                           CraftResourceScanner                         │
│  Starter Kit system     → ProjectBuilder, DependencyAnalyzer,          │
│                           BlueprintBuilder, installation/*,             │
│                           synchronization/* (whole-site scope)          │
│  Dependency layer       → DependencyResolverService,                   │
│                           SharedResourceRegistryService                │
│  Publishing layer       → PackageBuilderService, PublishValidatorService,│
│                           RepositoryManagerService, PackagePublisherService│
│  Commerce layer         → CommerceClient, LicenseService,              │
│                           SubscriptionService, PlanService,             │
│                           commerce\PackageService, FeatureGateService   │
└───────────────────────────────────────────────────────────────────────┘
   ↓
Storage / Archives  (packages/{handle}/ on disk; storage/site7-studio/{exports,
                      marketplace-repo, commerce24-cache, runtime}/; .s7pkg zip files)
   ↓
Host site resources/files  (templates/_blocks/*.twig; Craft Fields/Entry Types/
                             Sections/Volumes/Category&Tag Groups; frontend/
                             owned files; config/project/*.yaml via rebuild())
```

Every box above is a real directory/class group — none invented. See `02_DIRECTORY_STRUCTURE.md` for the exact filesystem mapping.

## A. Current implemented architecture (verified against code)

The layers above, and every numbered document in this set describing "Current Status: Implemented," are directly confirmed against the current source — either by direct file reading or by live DDEV verification performed during the package-lifecycle work referenced throughout this documentation set (Steps 2 through 8.2 of that work, commit history `f8bd169` … `438ce75` on branch `cleanup/dead-templates-checkpoint-20260817`).

## B. Intention / design comments found in code (not necessarily fully realized)

Several classes carry docblocks describing a broader intended architecture than what currently executes — for example, `PackageManifest`'s Website Starter Kit System fields are explicitly commented "reserved... no install logic reads these yet" for several fields, and the Starter Kit installation pipeline (`32_STARTER_KIT_SYSTEM.md`) has phase docs describing a design that was only partially validated against real-world data. Wherever a document in this set states something is only a documented *intention*, it is explicitly labeled "PARTIAL" or "PLANNED," never "Implemented."

## C. Known limitations

Tracked centrally in `43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md`, and referenced per-feature in each document's own "Known Limitations" section.

## D. Future / not implemented

Also tracked in `43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md` — package signing (`NullPackageSigner`), any CP UI specifically for owned-file update/rollback, rename/move detection for owned files, and others.

## Bootstrap-level components (what Craft actually instantiates)

- **Plugin bootstrap**: `src/Site7Studio.php`, `src/base/PluginTrait.php` — see `03_BOOTSTRAP_AND_PLUGIN_LIFECYCLE.md`.
- **Providers** (`src/providers/*.php`): `CoreServiceProvider`, `EventServiceProvider`, `CpServiceProvider`, `LibraryServiceProvider`, `ImportServiceProvider`, `CommerceServiceProvider`, `PublishingServiceProvider`.
- **Services** (`src/services/**/*.php`): the bulk of the domain logic, ~70 named components — see `38_SERVICE_REFERENCE.md`.
- **Models** (`src/models/**/*.php`): plain `craft\base\Model` DTOs — never persistence-aware.
- **Records** (`src/records/*.php`): `craft\db\ActiveRecord` subclasses, mostly bare — one per database table, see `05_DATABASE_ARCHITECTURE.md`.
- **Migrations** (`src/migrations/*.php`): 15 timestamped migrations + `Install.php`.
- **Controllers** (`src/controllers/*.php`, `src/console/controllers/*.php`): see `28_CONTROLLERS_AND_ROUTES.md`, `30_CONSOLE_COMMANDS.md`.
- **Events** (`src/events/**/*.php`): see `27_EVENTS_AND_HOOKS.md`.
- **Repositories** (`src/repositories/**/*.php`): thin, table-scoped read/write helpers, distinct from full services — scoped specifically to the source-tracking tables (`site7_*_import_sources`) and marketplace file/HTTP "repositories."
- **Interfaces** (`src/interfaces/*.php`): contracts for genuinely pluggable pieces (marketplace repository, publish target, commerce client, license/subscription/package providers, version manager, package builder/publisher/validator/signer).
- **Executors** (`src/services/installation/executors/*.php`): one class per Starter Kit installation step type.
- **Scanners**: `FrontendToolingScanner`, `CraftResourceScanner`, `ComposerDependencyScanner`, `src/services/scanning/*.php` — all read-only detection of live Craft/filesystem state.
- **Validators**: `ResourceImportValidator`, `InstallationValidator`, `SynchronizationValidator`, `PublishValidatorService` — each scoped to exactly one pipeline stage.
- **Planners**: `InstallationPlanner`, `SynchronizationPlanner`, `PackageUpdatePlanner` — each strictly read-only, always separate from the executor that acts on its output.

## Two parallel, deliberately-separate "three-way comparison" systems

This is important enough to state at the architecture level, not just in the individual feature documents:

1. **`PackageUpdatePlanner` + `InstalledFileBaselineService`** — file-checksum granularity, for individual package-owned files (`template.twig`, owned CSS/JS). See `16_INSTALLED_FILE_BASELINE.md`, `19_UPDATE_AND_CONFLICT_HANDLING.md`.
2. **`SynchronizationPlanner` + `InstalledStarterKitTrackingService`** — whole-Blueprint granularity, for native Craft resources (Category/Tag Groups, Asset Volumes, Craft Sections) at the whole-Starter-Kit level. See `32_STARTER_KIT_SYSTEM.md`.

Both implement the same *conceptual* pattern (baseline / live / incoming, never silently overwrite a local change) at two different granularities, built independently. Their own class docblocks explicitly state the other system "stays untouched and out of scope." Do not merge them without a very strong, explicitly-reviewed reason (see `41_AI_DEVELOPER_GUIDE.md`).
