# 35 — Data Flow Reference

A cross-cutting index of INPUT → SERVICE → DATABASE/FILESYSTEM → OUTPUT for every major flow, for fast orientation. Each row links to the full feature document for detail.

| Flow | Input | Key Services (in order) | DB Tables Touched | Filesystem Touched | Doc |
|---|---|---|---|---|---|
| Import Existing Section | Craft Entry Type id | `MatrixEntryTypeImportService` → `ResourceClassifierService` → `EntryTypeSourceHasher` → `PackageManagerService` | `site7_packages`, `site7_section_import_sources`, `site7_package_versions` (via backup) | `packages/{handle}/*` created; live `_blocks/*.twig` read-only | `14_IMPORT_EXISTING_SECTION.md` |
| Import Existing Page | Craft Entry id | `PageImportService` | `site7_packages`, `site7_page_import_sources` | `packages/{handle}/*` created | `15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md` |
| Import Existing Website | Entry ids + Global Set ids | `WebsiteImportService` → `ComposerDependencyScanner` → `FrontendToolingScanner` | `site7_packages`, `site7_website_import_sources` | `packages/{handle}/*,frontend/*` created | `15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md` |
| Package Install | package handle | `PackageManagerService::installPackage()` → `CraftResourceService` → `InstalledFileBaselineService` → `DependencyResolverService` | `site7_packages`, `site7_installed_files` | `templates/_blocks/*.twig`, owned-file targets created | `11_PACKAGE_INSTALLATION.md` |
| Sync From Source | package handle | `SectionUpdateService`/`PageUpdateService` → `VersionManagerService` | `site7_package_versions` (conditionally) | `packages/{handle}/*` overwritten only | `18_SYNC_FROM_SOURCE.md` |
| Update Installed Package | package handle + target version | `PackageManagerService::updateInstalledFiles()` → `PackageUpdatePlanner` | `site7_installed_files` (safe cases only) | live targets, only for safe-classified files | `19_UPDATE_AND_CONFLICT_HANDLING.md` |
| Rollback | package handle + target version id | `PackageRollbackService` → `PackageArchiveHelper::replaceDirectory()` → `PackageManagerService::updateInstalledFiles()` | `site7_installed_files` | `packages/{handle}/` wholesale replace; live targets per three-way rules | `20_ROLLBACK.md` |
| Uninstall (4 variants) | package handle | `PackageManagerService::removePackage()`/`disablePackage()`/`deletePackage()`/`detachPackage()` | varies — see `12_PACKAGE_UNINSTALLATION.md` §4 | varies | `12_PACKAGE_UNINSTALLATION.md` |
| Create Version | package handle | `VersionManagerService` → `PackageExportService` | `site7_package_versions` | new `.s7pkg` archive | `17_PACKAGE_VERSIONING.md` |
| Starter Kit Build | selected site content | `ProjectBuilder` → `DependencyAnalyzer` → `BlueprintBuilder` → `StarterKitBuilder` | none directly (reads whole project state) | `blueprint.json` created | `32_STARTER_KIT_SYSTEM.md` |
| Starter Kit Install | `blueprint.json` | `InstallationPlanner` → `InstallationExecutor` (via subprocess `InstallationOrchestratorService`) | `site7_install_sessions`, `site7_installed_starter_kits` | Composer/npm/Craft resources/content/frontend, per plan | `32_STARTER_KIT_SYSTEM.md` |
| Starter Kit Sync | newer `blueprint.json` | `SynchronizationPlanner` → `SynchronizationOrchestratorService` (reuses install machinery) | `site7_sync_sessions`, `site7_sync_history`, `site7_installed_starter_kits` | same targets as install, per diff | `32_STARTER_KIT_SYSTEM.md` |
| Marketplace Install | repository handle + package handle | `MarketplaceService::installFromRepository()` → `PackageImportService::importPackage()` | same as `10_PACKAGE_IMPORT.md` | same as `10_PACKAGE_IMPORT.md` | `23_MARKETPLACE_ARCHITECTURE.md` |

This document intentionally duplicates no new facts — every row is a pointer into an already-verified feature document. Treat it as a lookup table, not a source of truth in itself.
