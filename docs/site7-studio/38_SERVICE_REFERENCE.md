# 38 — Service Reference

Flat lookup, grouped by domain, for "where is the service that does X." Each entry links to its full feature document for detail.

## Package Services
| Service | File | Doc |
|---|---|---|
| `PackageManagerService` | `src/services/PackageManagerService.php` | `11_PACKAGE_INSTALLATION.md`, `12_PACKAGE_UNINSTALLATION.md`, `19_UPDATE_AND_CONFLICT_HANDLING.md`, `21_FRONTEND_FILE_OWNERSHIP.md` |
| `PackageAuthoringService` | `src/services/PackageAuthoringService.php` | `08_PACKAGE_AUTHORING.md` |
| `PackageUsageService` | `src/services/PackageUsageService.php` | `12_PACKAGE_UNINSTALLATION.md` |

## Import Services
| Service | File | Doc |
|---|---|---|
| `MatrixEntryTypeImportService` | `src/services/import/MatrixEntryTypeImportService.php` | `14_IMPORT_EXISTING_SECTION.md` |
| `CraftSectionImportService` | `src/services/import/CraftSectionImportService.php` | `14_IMPORT_EXISTING_SECTION.md` |
| `ResourceClassifierService` | `src/services/import/ResourceClassifierService.php` | `14_IMPORT_EXISTING_SECTION.md` |
| `EntryTypeSourceHasher` | `src/services/import/EntryTypeSourceHasher.php` | `14_IMPORT_EXISTING_SECTION.md` |
| `SectionUpdateService` | `src/services/import/SectionUpdateService.php` | `18_SYNC_FROM_SOURCE.md` |
| `PageImportService` / `PageUpdateService` | `src/services/import/Page*.php` | `15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md` |
| `EntrySourceHasher` | `src/services/import/EntrySourceHasher.php` | `15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md` |
| `WebsiteImportService` | `src/services/import/WebsiteImportService.php` | `15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md` |
| `StarterKitSyncService` | `src/services/import/StarterKitSyncService.php` | `15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md`, `32_STARTER_KIT_SYSTEM.md` |
| `ResourceImportValidator` | `src/services/import/ResourceImportValidator.php` | `14_IMPORT_EXISTING_SECTION.md` |

## Publishing Services
| Service | File | Doc |
|---|---|---|
| `VersionManagerService` | `src/services/publishing/VersionManagerService.php` | `17_PACKAGE_VERSIONING.md` |
| `PackageExportService` | `src/services/publishing/PackageExportService.php` | `09_PACKAGE_BUILD_AND_EXPORT.md` |
| `PackageBuilderService` | `src/services/publishing/PackageBuilderService.php` | `09_PACKAGE_BUILD_AND_EXPORT.md` |
| `PackagePublisherService` | `src/services/publishing/PackagePublisherService.php` | `09_PACKAGE_BUILD_AND_EXPORT.md`, `24_LICENSING_AND_COMMERCE.md` |
| `PublishValidatorService` | `src/services/publishing/PublishValidatorService.php` | `09_PACKAGE_BUILD_AND_EXPORT.md` |
| `PackageRollbackService` | `src/services/publishing/PackageRollbackService.php` | `20_ROLLBACK.md` |
| `NullPackageSigner` | `src/services/publishing/NullPackageSigner.php` | `24_LICENSING_AND_COMMERCE.md` |

## Synchronization Services (single-file three-way system)
| Service | File | Doc |
|---|---|---|
| `PackageUpdatePlanner` | `src/services/synchronization/PackageUpdatePlanner.php` | `19_UPDATE_AND_CONFLICT_HANDLING.md` |
| `InstalledFileBaselineService` | `src/services/synchronization/InstalledFileBaselineService.php` | `16_INSTALLED_FILE_BASELINE.md` |

## Synchronization Services (whole-Starter-Kit system — structurally separate, see `01_ARCHITECTURE.md`)
| Service | File | Doc |
|---|---|---|
| `SynchronizationPlanner` / `SynchronizationValidator` / `SynchronizationOrchestratorService` | `src/services/synchronization/*.php` | `32_STARTER_KIT_SYSTEM.md` |
| `InstalledStarterKitTrackingService` | `src/services/synchronization/InstalledStarterKitTrackingService.php` | `32_STARTER_KIT_SYSTEM.md` |

## Installation Services (Starter Kit)
| Service | File | Doc |
|---|---|---|
| `InstallationPlanner` / `InstallationValidator` / `InstallationExecutor` / `InstallationStageRunner` / `InstallationOrchestratorService` / `StarterKitCatalogService` | `src/services/installation/*.php` | `32_STARTER_KIT_SYSTEM.md` |
| Step executors | `src/services/installation/executors/*.php` | `32_STARTER_KIT_SYSTEM.md` |

## Template / Frontend Services
| Service | File | Doc |
|---|---|---|
| `CraftResourceService` | `src/services/CraftResourceService.php` | `04_CRAFT_CMS_INTEGRATION.md`, `13_TEMPLATE_ARCHITECTURE.md` |
| `FrontendToolingScanner` | `src/services/FrontendToolingScanner.php` | `22_FRONTEND_TOOLING_AND_ASSET_DETECTION.md` |

## Marketplace / Dependency Services
| Service | File | Doc |
|---|---|---|
| `MarketplaceService` | `src/services/MarketplaceService.php` | `23_MARKETPLACE_ARCHITECTURE.md` |
| `DependencyResolverService` | `src/services/DependencyResolverService.php` | `25_DEPENDENCIES_AND_SHARED_RESOURCES.md` |
| `DependencyAnalyzer` | `src/services/DependencyAnalyzer.php` | `25_DEPENDENCIES_AND_SHARED_RESOURCES.md`, `32_STARTER_KIT_SYSTEM.md` |
| `SharedResourceRegistryService` / `SharedResourceUsageService` | `src/services/*.php` | `25_DEPENDENCIES_AND_SHARED_RESOURCES.md` |

## Commerce Services
| Service | File | Doc |
|---|---|---|
| `LicenseService`, `FeatureGateService`, `PlanService`, `SubscriptionService`, `commerce/PackageService`, `commerce/UpdateService`, `DownloadService`, `CommerceClient` | `src/services/commerce/*.php` | `24_LICENSING_AND_COMMERCE.md` |

## Support Services
| Service | File | Doc |
|---|---|---|
| `PackageArchiveHelper` | `src/services/support/PackageArchiveHelper.php` (also referenced as `src/helpers/`) | `06_PACKAGE_ARCHITECTURE.md`, `09_PACKAGE_BUILD_AND_EXPORT.md`, `16_INSTALLED_FILE_BASELINE.md` |
| `PackageBackupService` | `src/services/support/PackageBackupService.php` | `26_BACKUP_AND_RECOVERY.md` |

## Starter Kit Build Services
| Service | File | Doc |
|---|---|---|
| `ProjectBuilder`, `BlueprintBuilder`, `StarterKitBuilder` | `src/services/*.php` | `32_STARTER_KIT_SYSTEM.md` |
