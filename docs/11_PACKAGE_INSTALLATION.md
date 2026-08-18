# 11 — Package Installation

## 1. Purpose

Explain exactly what happens when a package is installed onto a site — the single most consequential operation in the plugin, since it's the only place Craft resources and installed template/owned files are actually written.

## 2. What It Does

`PackageManagerService::installPackage($handle)` creates/reuses the Craft Fields and Entry Type a Section package needs, copies its template to the real rendering path, installs any explicitly-owned files, and records baselines for everything it wrote.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
installPackage($handle)
   ↓ resolve manifest.dependencies.sharedResources → DependencyResolverService::resolveSharedResources()
   ↓   (missing/dead Shared Resource → warning only, NEVER blocks install)
   ↓ type-specific cascade: Pattern requires Sections → recursively install+enable;
   ↓   Template requires Patterns/Sections → same; Starter-Kit requires Templates → same
   ↓ if type === 'section': CraftResourceService::generateResources($packagePath)
   ↓   → createCraftField() per fields.yaml entry — idempotent
   ↓   → createMatrixEntryType() per matrix.yaml block — idempotent
   ↓   → copy template.twig → templates/_blocks/{blockHandle}.twig (content-compare guarded)
   ↓   → InstalledFileBaselineService::record() for the template
   ↓ installOwnedFiles($record, $packagePath) — type-agnostic loop over manifest.ownedFiles
   ↓ record->status = 'installed'; DB transaction commit; invalidateCraftCaches()
   ↓ (on ANY failure: transaction rollback + craftResourceGenerator->removeResources() undo)
```
`enablePackage($handle)` is a separate, required follow-up call that links a Section into the live Site7 Matrix field (`linkToMatrix()`) — install alone never does this.

## 5. Execution Flow

1. Load the `PackageRecord`; return `false` if not found.
2. Resolve Shared Resource dependencies (`DependencyResolverService::resolveSharedResources()`) — collects warnings into `$_lastInstallWarnings`, never blocks.
3. Type-specific cascade: if `pattern`, install+enable every required `section`; if `template`, install+enable every required `pattern`/`section`; if `starter-kit`, install+enable every required `template`. Each cascade call recursively re-enters `installPackage()`.
4. Resolve the package's on-disk path (`packages/{handle}/`, falling back to `tests/fixtures/packages/{handle}/` for test fixtures only).
5. Begin a DB transaction.
6. If `type === 'section'`: `CraftResourceService::generateResources($packagePath)` — see `04_CRAFT_CMS_INTEGRATION.md` for the Field/Entry Type creation detail and `13_TEMPLATE_ARCHITECTURE.md` for the template-copy guard. Record the template's baseline if it was actually copied.
7. `installOwnedFiles($record, $packagePath)` — loops `manifest->ownedFiles`; for each, copies source→target (same content-compare guard) and records a baseline. No-op if `ownedFiles` is empty (every package before the owned-files feature, or any package whose author selected nothing).
8. Set `status = 'installed'`, save, commit transaction, invalidate Craft caches.
9. On any thrown exception: roll back the transaction and call `craftResourceGenerator->removeResources($generatedResources)` to undo whatever Craft resources this specific call created.

## 6. Important Classes

**`PackageManagerService`**
`src/services/PackageManagerService.php`
Responsibility: install orchestration, owned-file install, enable/disable/remove/delete.
Important methods: `installPackage(string $handle): bool`, `enablePackage(string $handle): bool`, `installOwnedFiles(PackageRecord $record, string $packagePath): void` (private), `getPackagePath()`, `discoverPackages()`.
Called by: `PackageActionController::actionInstall()`, import services (immediately after writing a new package), `PackageImportService`, `MarketplaceService`.
Dependencies: `CraftResourceService`, `DependencyResolverService`, `InstalledFileBaselineService`, `PackageArchiveHelper`.
Side effects: creates/updates Craft Fields/Entry Types, writes `templates/_blocks/*.twig` and owned-file targets, writes `site7_installed_files` rows.

## 7. Data Model

`site7_packages.status` transitions to `'installed'`. `site7_installed_files` gains one row per file actually written (template + each owned file). See `16_INSTALLED_FILE_BASELINE.md`.

## 8. Filesystem Impact

**Created**: `templates/_blocks/{blockHandle}.twig` (if not already present or already identical); owned-file targets, same guard.
**Modified**: same paths, on a re-install that finds identical content (idempotent re-copy, harmless).
**Never modified/deleted**: a target file whose content differs from the package's own copy — skipped, warning recorded in `$_lastInstallWarnings`, never overwritten.

## 9. Events

None dispatched directly by `installPackage()` itself (the *caller*, e.g. an import service, dispatches its own event afterward).

## 10. Validation and Safety

**Idempotency**: `createCraftField()`/`createMatrixEntryType()` look up by handle first (§`04_CRAFT_CMS_INTEGRATION.md`); the template/owned-file copy only proceeds if the target is missing or byte-identical to the source.

**Transactional**: the whole install runs in one DB transaction; any failure rolls back the DB state and undoes any newly-created Craft resources.

**Dependency resolution never blocks**: a missing/dead Shared Resource is a warning, not an error — install always completes for the resources it CAN resolve.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Package not found | `installPackage()` returns `false` immediately |
| Required Pattern/Section/Template missing | Throws `Exception("Required ... package '...' was not found.")` |
| A Shared Resource is missing/dead | Warning collected, install continues |
| `Craft::$app->getFields()->saveField()` returns false | Exception thrown inside the try block → transaction rollback + resource undo |
| Template target already exists with different content | Skipped, warning recorded, install still succeeds overall |
| Owned file target already exists with different content | Same skip-and-warn behavior, per file |

## 12. Developer Change Guide

If changing package installation:
```
PackageManagerService::installPackage()
   ↓
CraftResourceService (Field/Entry Type creation, template copy)
   ↓
InstalledFileBaselineService (baseline recording)
```
Do not add a second install entry point — every "make this package live on this site" path (fresh install, Marketplace update, rollback) ultimately calls `installPackage()` or the narrower `updateInstalledFiles()` (`19_UPDATE_AND_CONFLICT_HANDLING.md`), never a third mechanism.

## 13. Related Features

`04_CRAFT_CMS_INTEGRATION.md`, `13_TEMPLATE_ARCHITECTURE.md`, `16_INSTALLED_FILE_BASELINE.md`, `21_FRONTEND_FILE_OWNERSHIP.md`, `25_DEPENDENCIES_AND_SHARED_RESOURCES.md`.

## 14. Known Limitations

- The template-copy step assumes a Section package maps to exactly one target file (`matrix.yaml`'s first block handle) — documented in code as an "MVP: assume first block handle" simplification.
- `installOwnedFiles()` is type-agnostic in principle, but in current practice only Section packages populate `ownedFiles` (only `MatrixEntryTypeImportService` calls `captureOwnedFiles()`).
