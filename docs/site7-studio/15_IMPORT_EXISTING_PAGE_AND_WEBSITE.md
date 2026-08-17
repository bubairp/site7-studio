# 15 — Import Existing Page and Website

## 1. Purpose

Document the two remaining import sources — a single Entry (Page) and a whole Website — which produce `TemplatePackage`/`StarterKitPackage` types respectively.

## 2. What It Does

`PageImportService` captures one Entry's field values into a Template package. `WebsiteImportService` captures multiple Entries + Global Sets + a project-wide environment snapshot into a Starter Kit package — the entry point into the separate whole-site system documented in `32_STARTER_KIT_SYSTEM.md`.

## 3. Current Status

**Implemented**, with documented real-world capture gaps for the Website path — see §14 and `43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md`.

## 4. Architecture — Import Existing Page

```
Existing Craft Entry
   ↓
Entry's own field values (via EntrySourceHasher — also hashes ordered Site7 Matrix
   block composition when present)
   ↓
TemplatePackage referencing the Section packages its Matrix blocks came from,
   via `requires` — NEVER duplicating their content
   ↓
PageImportSourceRepository (1:1, keyed by Entry uid)
   ↓
PackageManagerService::discoverPackages() → installPackage() → enablePackage()
   ↓
ResourceImportedEvent → PackageBackupSubscriber (same as Section import)
```

## 4b. Architecture — Import Existing Website

```
Selected Entries (pages) + selected Global Sets
   ↓
Project-wide environment snapshot (NOT scoped to the selection):
   ComposerDependencyScanner::captureComposerPluginDependencies() — every installed plugin
   FrontendToolingScanner::detect()/captureNpmDependencies() — build system + npm deps
   Referenced-only native resources (Asset Volumes/Category Groups/Tag Groups/Craft Sections
   actually used by captured content — never a blanket project dump)
   ↓
copyFrontendConfigFiles() — detected CONFIG files only (never source/build output) into
   packages/{handle}/frontend/ (see 22_FRONTEND_TOOLING_AND_ASSET_DETECTION.md for the
   scope boundary vs. explicit ownedFiles, 21_FRONTEND_FILE_OWNERSHIP.md)
   ↓
WebsiteImportSourceRepository (1:1, keyed by a computed selectionKey — sha256 of the sorted,
   deduplicated captured-entry uid list, since a website has no single natural source uid)
   ↓
StarterKitPackage, `pages` array referencing Template packages by handle (never duplicating
   page content)
```

## 5. Execution Flow — Page

1. `PageImportService::importFromEntry(int $entryId, array $meta)` — loads the Entry, throws if not found.
2. Duplicate-import guard via `PageImportSourceRepository::findBySourceUid()`.
3. Field values extracted; Site7 Matrix block composition (if present) hashed via `EntrySourceHasher`.
4. `TemplatePackage` written, `requires` populated with the Section package handles the page's Matrix blocks reference.
5. `discoverPackages()` → `installPackage()` → `enablePackage()`; `ResourceImportedEvent` dispatched → automatic backup.

**Update**: `PageUpdateService::diff()`/`updateInPlace()` — same "compare live vs. stored, no-op if unchanged, exactly one version if changed" pattern as `SectionUpdateService` (`18_SYNC_FROM_SOURCE.md`), field-values-only (no Twig-equivalent concept for a whole page).

## 5b. Execution Flow — Website

1. `WebsiteImportService::importWebsite(array $entryIds, array $globalSetIds, array $meta)`.
2. Captures selected content + the project-wide environment snapshot (§4b).
3. `copyFrontendConfigFiles()` copies only detected config filenames (see `19_FRONTEND_TOOLING` scope).
4. `WebsiteImportSourceRepository::record()` — 1:1 by computed `selectionKey`.
5. `discoverPackages()` → `installPackage()` → `enablePackage()`; `ResourceImportedEvent` → automatic backup.
6. This is the entry point that a downstream `StarterKitBuilder` call (console-only, or via `ResourceImportController::actionImportWebsite()` — see `32_STARTER_KIT_SYSTEM.md` for the wiring history) turns into an installable `blueprint.json`.

**Sync**: `StarterKitSyncService::synchronize()` delegates into the Starter Kit System's own Synchronization Engine (`32_STARTER_KIT_SYSTEM.md`) — a structurally different mechanism from `SectionUpdateService`/`PageUpdateService`, operating on native Craft resources rather than package files.

## 6. Important Classes

**`PageImportService`** — `src/services/import/PageImportService.php`. Methods: `importFromEntry()`.
**`PageUpdateService`** — `src/services/import/PageUpdateService.php`. Methods: `diff()`, `updateInPlace()`.
**`EntrySourceHasher`** — `src/services/import/EntrySourceHasher.php`.
**`WebsiteImportService`** — `src/services/import/WebsiteImportService.php`. Methods: `importWebsite()`, `copyFrontendConfigFiles()` (private).
**`StarterKitSyncService`** — `src/services/import/StarterKitSyncService.php`.
**`PageImportSourceRepository`** / **`WebsiteImportSourceRepository`** — `src/repositories/*.php`.

## 7. Data Model

`site7_page_import_sources` (1:1, `packageId` unique + `sourceUid` unique — no `sourceType` column, unlike the Section table). `site7_website_import_sources` (1:1, `packageId` unique + `selectionKey` unique, `sourceEntryUids` stored as JSON).

## 8. Filesystem Impact

Page: `packages/{handle}/manifest.json` + template-package structure, no Twig capture (a page has no single Twig file the way a Section does).
Website: `packages/{handle}/manifest.json,frontend/*` (config files only), plus whatever the downstream Starter Kit build produces (`blueprint.json`, see `32_STARTER_KIT_SYSTEM.md`).

## 9. Events

`ResourceImportedEvent` for both — same automatic-backup side effect as Section import.

## 10. Validation and Safety

Same duplicate-import-guard pattern as Section import, at each service's own identity key (Entry uid for Page, `selectionKey` for Website).

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Entry already imported (Page) | Throws with existing package handle |
| Same entry-set selection already imported (Website) | Throws with existing package handle |
| No package.json found for frontend detection (Website) | `frontendTooling`/`npmPackages` simply empty — not an error |

## 12. Developer Change Guide

If changing Page import: `PageImportService` mirrors `MatrixEntryTypeImportService`'s shape closely — keep them structurally parallel when changing shared concepts (duplicate-guard, backup-on-import).

If changing Website import: remember this is the entry point into an entirely separate subsystem (`32_STARTER_KIT_SYSTEM.md`) — a change here can have effects well beyond this document's scope.

## 13. Related Features

`14_IMPORT_EXISTING_SECTION.md`, `18_SYNC_FROM_SOURCE.md`, `22_FRONTEND_TOOLING_AND_ASSET_DETECTION.md`, `32_STARTER_KIT_SYSTEM.md`.

## 14. Known Limitations

Per the plugin's own `VALIDATION-REPORT-FULL-PIPELINE.md` (an older document, re-verified facts summarized here, not depended upon as ground truth): real-data testing found significant Website-import capture gaps — `TemplateGeneratorService::generateFromEntry()` (a related "Save as Template" mechanism) only works for pages authored through Site7 Studio's own visual builder (0 of 885 real traditionally-authored entries qualified in that test); Category/Tag field VALUES (not group settings) are never captured/re-linked; blank-title entries fail capture with an unhelpful error. See `43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md` for the full, consolidated list — some of these were reportedly fixed in later work not independently re-verified while writing this documentation set.
