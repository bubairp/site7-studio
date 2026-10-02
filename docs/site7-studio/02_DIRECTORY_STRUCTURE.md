# 02 — Directory / Codebase Map

```
plugins/site7-studio/
├── src/
│   ├── Site7Studio.php              # Plugin entry class - bootstrap, CP routes, isDevMode()
│   ├── base/PluginTrait.php         # @property-read component accessors mixin
│   ├── config.php                    # Plugin config defaults
│   ├── providers/                    # ServiceProviderInterface implementations (7 files)
│   ├── controllers/                  # CP web controllers (15 files)
│   ├── console/controllers/          # Console commands (5 files)
│   ├── services/                     # Domain services - largest directory
│   │   ├── import/                   # Import Existing X + Sync From Source services
│   │   ├── publishing/               # Build/export/publish/version pipeline + Rollback
│   │   ├── synchronization/          # Starter Kit sync engine + PackageUpdatePlanner/InstalledFileBaselineService
│   │   ├── installation/             # Starter Kit installation pipeline
│   │   │   └── executors/            # One class per install step type
│   │   ├── support/                  # PackageArchiveHelper, PackageBackupService - stateless helpers
│   │   ├── commerce/                 # Commerce24 client + business services
│   │   ├── scanning/                 # Read-only Craft-state scanners (native resources)
│   │   └── engine/                   # PackageReader, PackageDiscovery
│   ├── models/                       # Plain Model DTOs - never DB-backed, never business logic
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
├── docs/                             # Plugin's OLDER documentation (phase docs, validation reports) - not
│   │                                  # depended on by this new docs/site7-studio/ set (see 00_OVERVIEW.md)
│   └── site7-studio/                 # THIS documentation set
├── tests/                            # Codeception/PHPUnit test suite - see 33_TESTING_ARCHITECTURE.md
├── packages/                         # Package SOURCE directory - the plugin's own managed storage
├── codeception.yml, phpunit.xml.dist
└── composer.json
```

## Per-directory rules

| Directory | Belongs there | Does NOT belong there |
|---|---|---|
| `services/` (root) | Cross-cutting domain services used by many callers (`PackageManagerService`, `CraftResourceService`, `DependencyResolverService`) | Anything scoped to one import source or one pipeline stage — those go in a subdirectory |
| `services/import/` | Import Existing X services, Sync From Source, resource classification/discovery/validation | Craft-resource *installation* logic (that's `CraftResourceService`, in `services/`) |
| `services/publishing/` | Build/validate/publish/version-history services, `PackageRollbackService` | Marketplace repository implementations (`repositories/marketplace/`) |
| `services/synchronization/` | Both the whole-Starter-Kit `SynchronizationPlanner` AND the file-level `PackageUpdatePlanner`/`InstalledFileBaselineService` — two related but code-independent mechanisms sharing one directory by convention | A new third "conflict system" — reuse one of the two existing ones |
| `services/support/` | Genuinely stateless, dependency-free helpers (`PackageArchiveHelper` is pure static methods) | Anything with its own state/DB access |
| `models/packages/` | `Package`/`PackageManifest`/type subclasses — data only | Any method that writes to disk or the DB |
| `records/` | One bare (or near-bare) `ActiveRecord` per table | Business logic — logic lives in the owning service |
| `repositories/` | Table-scoped CRUD for **source-tracking** tables specifically | General package CRUD — that's `PackageManagerService`/`PackageRepository` |
| `interfaces/` | A contract with more than one real or plausible implementation | A contract for something that will only ever have one implementation |

## Locating a feature by directory

| If you're looking for... | Look in |
|---|---|
| Package install/enable/disable/delete | `services/PackageManagerService.php` |
| Craft Field/Entry Type creation | `services/CraftResourceService.php` |
| Import Existing Section/Page/Website | `services/import/*ImportService.php` |
| Sync From Source | `services/import/SectionUpdateService.php`, `PageUpdateService.php` |
| Version creation | `services/publishing/VersionManagerService.php` |
| Archive/checksum primitives | `services/support/PackageArchiveHelper.php` |
| Installed-file baseline | `services/synchronization/InstalledFileBaselineService.php` |
| Update/conflict decisions | `services/synchronization/PackageUpdatePlanner.php` |
| Rollback | `services/publishing/PackageRollbackService.php` |
| Frontend detection / owned-file discovery | `services/FrontendToolingScanner.php` |
| Marketplace/Commerce | `services/MarketplaceService.php`, `services/commerce/*`, `repositories/marketplace/*` |
| Whole-site Starter Kit system | `services/ProjectBuilder.php`, `DependencyAnalyzer.php`, `BlueprintBuilder.php`, `services/installation/`, `services/synchronization/Synchronization*.php` |
