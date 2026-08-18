# 28 — Controllers and Routes

## 1. Purpose

Full inventory of every CP controller and its actions, and how navigational routes get registered.

## 2. What It Does

Standard Craft `\craft\web\Controller` subclasses under `src/controllers/`, handling both full-page CP navigation and AJAX/POST actions from the various CP screens.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
craft\web\UrlManager::EVENT_REGISTER_CP_URL_RULES  (Site7Studio::attachEventHandlers(), ~line 228)
   ↓
explicit navigational GET route rules (site7-studio, /settings, /setup, /library, /packages/*,
   /publishing, /marketplace, /commerce, /install/*, /update/*)
   ↓
POST/AJAX actions reached via Craft's DEFAULT controller-action routing
   (the "action" hidden form field / fetch body — no explicit rule needed per action)
```

## 5. Execution Flow

Only navigational (page-load) GET routes get explicit `$event->rules[...]` entries; every action-level POST/AJAX endpoint is resolved by Craft's own default `{plugin-handle}/{controller}/{action}` convention, independent of the explicit rule list.

## 6. Important Classes — full controller/action inventory

| Controller | File | Actions |
|---|---|---|
| `CommerceController` | `src/controllers/CommerceController.php` | actionIndex, actionDisconnectAccount, actionActivateLicense, actionDeactivateLicense, actionRefreshLicense, actionValidateLicense, actionTransferLicense, actionUpgradePlan, actionDowngradePlan, actionRenewSubscription, actionCancelSubscription, actionOpenCustomerPortal, actionOpenAccountPortal, actionInstallPackage, actionRemovePendingPackage, actionCheckUpdates, actionUpdateAll, actionUpdateSelected |
| `DefaultController` | `src/controllers/DefaultController.php` | actionIndex |
| `InstallWizardController` | `src/controllers/InstallWizardController.php` | actionIndex, actionValidate, actionPreview($sessionUid), actionExecute, actionProgress($sessionUid), actionSummary($sessionUid) |
| `LibraryController` | `src/controllers/LibraryController.php` | actionIndex, actionPackage($handle), actionPreview($handle), actionRenderPreview($handle), actionPreviewImage($handle) |
| `MarketplaceController` | `src/controllers/MarketplaceController.php` | actionIndex, actionExport, actionImportUpload, actionImportInstall, actionImportCancel, actionUpdatePackage, actionInstallFromRepository, actionReinstallPackage, actionRepairPackage |
| `PackageActionController` | `src/controllers/PackageActionController.php` | actionInstall, actionEnable, actionDisable, actionRemove, actionDelete, actionDetach, actionGetPatternBlocks, actionGetTemplateBlocks, actionGetBrowserData |
| `PackageAuthoringController` | `src/controllers/PackageAuthoringController.php` | actionNew, actionCreate, actionEdit($handle), actionSave, actionUploadPreviewImage, actionSaveFields, actionSavePattern, actionSaveTemplate, actionSaveStarterKit |
| `PackagePublisherController` | `src/controllers/PackagePublisherController.php` | actionIndex, actionWizard($handle), actionSaveMetadata, actionPublish, actionCreateVersion |
| `ResourceImportController` | `src/controllers/ResourceImportController.php` | actionGetMatrixEntryTypes, actionEntryTypeDetail, actionGetCraftSections, actionAnalyzeSection, actionImportSection, actionDiffSectionUpdate, actionUpdateSectionPackage, actionGetPages, actionGetWebsiteTree, actionDiffPageUpdate, actionUpdatePagePackage, actionAnalyzePage, actionImportPage, actionGetWebsiteResources, actionAnalyzeWebsite, actionImportWebsite, actionGetStarterKitReferences, actionSyncStarterKit, actionListFrontendFileCandidates |
| `SettingsController` | `src/controllers/SettingsController.php` | actionIndex, actionSave, actionTestConnection |
| `SetupController` | `src/controllers/SetupController.php` | actionIndex, actionSave, actionComplete |
| `SharedResourceController` | `src/controllers/SharedResourceController.php` | actionIndex, actionPreview($handle), actionImport, actionExport($handle), actionUpdate, actionDelete |
| `StarterKitGeneratorController` | `src/controllers/StarterKitGeneratorController.php` | actionGetEntries, actionSaveAsStarterKit, actionInstall |
| `TemplateGeneratorController` | `src/controllers/TemplateGeneratorController.php` | actionSaveAsTemplate, actionGetCreateOptions, actionCreateFromTemplate |
| `UpdateWizardController` | `src/controllers/UpdateWizardController.php` | actionIndex, actionPlan, actionExecute, actionProgress($sessionUid), actionSummary($sessionUid) |

## 7. Data Model

Not applicable — controllers are the HTTP boundary, delegating to services for all persistence.

## 8. Filesystem Impact

None directly — delegated to the services each controller calls.

## 9. Events

None dispatched by controllers directly — controllers call services, which dispatch domain events (`27_EVENTS_AND_HOOKS.md`).

## 10. Validation and Safety

Standard Craft CP controller conventions apply (`requireCpRequest()`/`requirePostRequest()`/permission checks), but this is uneven, not universal — verified directly: only 3 of 15 controllers call `requirePermission()` at all (`MarketplaceController`: `manageMarketplace`; `CommerceController`: `manageCommerce`/`manageLicense`/`manageSubscription`/`managePackages`/`manageUpdates`; `PackagePublisherController`: `viewPublishHistory`/`publishPackages`/`managePackageMetadata`/`manageVersions`). **`PackageActionController`** — which handles `actionInstall`/`actionEnable`/`actionDisable`/`actionRemove`/`actionDelete`/`actionDetach`, i.e. every destructive package operation — has **zero** `requirePermission()` calls anywhere in the file; it relies only on `requirePostRequest()`, plus a Dev-Mode gate for `actionDelete`/`actionDetach` specifically. `actionInstall`/`actionEnable`/`actionDisable`/`actionRemove` have neither a permission gate nor a Dev-Mode gate — any CP user with general CP access can invoke them.

## 11. Failure Scenarios

Not applicable at this document's level of detail — see the specific feature document for each controller's domain (e.g. `19_UPDATE_AND_CONFLICT_HANDLING.md` for `UpdateWizardController`).

## 12. Developer Change Guide

If adding a new CP page: add a navigational GET rule inside the `EVENT_REGISTER_CP_URL_RULES` handler in `src/Site7Studio.php` (~line 228) — action-level endpoints need no explicit rule, Craft's default routing already resolves them.

## 13. Related Features

`27_EVENTS_AND_HOOKS.md`, `29_CP_UI_ARCHITECTURE.md`, `03_BOOTSTRAP_AND_PLUGIN_LIFECYCLE.md`.

## 14. Known Limitations

None confirmed at this document's scope.
