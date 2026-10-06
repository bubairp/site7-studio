# 14 — Import Existing Section

## 1. Purpose

Document the most detailed and most exercised import flow — turning an existing, live Craft Entry Type (loose, or belonging to a native Craft Section) into a SITE7 Section package.

## 2. What It Does

`MatrixEntryTypeImportService` (single Entry Type) and `CraftSectionImportService` (a native Craft Section with multiple Entry Types — delegates per-Entry-Type to the former) capture a live Entry Type's field layout AND its real, already-styled Twig template into a new package.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
Existing Craft site
   ↓
Existing Craft Section (optional layer) → Entry Type
   ↓
Matrix field field-layout
   ↓  ResourceClassifierService::classifyFieldLayout()
   ↓  → capturable fields written to fields.yaml/matrix.yaml; SHARED_RESOURCE registered,
   ↓     never duplicated; PLUGIN_DEPENDENCY/PLATFORM_CONFIGURATION/REVIEW_REQUIRED recorded
   ↓     to excludedFields, never captured
   ↓
templates/_blocks/{entryType.handle}.twig (REAL file, if it exists)
   ↓  copyTemplateTwigFromLiveSource() — read-only against the source; stub fallback ONLY if
   ↓  no real file exists
   ↓
manifest.json written (importedFrom.sourceHash via EntryTypeSourceHasher — structural hash,
   name excluded so a cosmetic rename alone doesn't trigger "Update Available")
   ↓
SectionImportSourceRepository::record() — 1:1 packageId↔sourceUid
   ↓
PackageManagerService::discoverPackages() → installPackage() → enablePackage()
   ↓
ResourceImportedEvent dispatched
   ↓
PackageBackupSubscriber → PackageBackupService::backupToLocalRepository()
   (real .s7pkg archive + first site7_package_versions row, version "1.0.0")
   ↓
SITE7 package v1, with a real archive
```

## 5. Execution Flow

1. `MatrixEntryTypeImportService::importFromEntryType(int $entryTypeId, array $meta)` — loads the Entry Type; throws if not found.
2. **Duplicate-import guard**: `SectionImportSourceRepository::findBySourceUid($entryType->uid)` — an Entry Type can be imported exactly once; re-attempting throws with the existing package's handle. The only way to change an imported Section afterward is Sync From Source (`18_SYNC_FROM_SOURCE.md`).
3. `detectFields($entryType)` → `ResourceClassifierService::classifyFieldLayout()` — every field is classified into one of 10 categories; only `FEATURE_RESOURCE`/`FEATURE_DEPENDENCY`/`NESTED_RESOURCE`/`REUSABLE_COMPONENT` (`isCapturable()`) are written into `fields.yaml`. `SHARED_RESOURCE` fields are registered (never duplicated); `PLUGIN_DEPENDENCY`/`PLATFORM_CONFIGURATION`/`REVIEW_REQUIRED` are recorded to `excludedFields` for transparency, never captured.
4. `EntryTypeSourceHasher::computeHash($entryType)` — a normalized, sorted structural hash of handle/type/instructions/settings (deliberately excludes `name`, so a cosmetic rename alone doesn't trigger "Update Available").
5. `ResourceImportValidator::validateImport()` — a proposed handle is generated and validated; a handle collision is auto-suffixed, never a hard failure.
6. `manifest.json`, `README.md`, `fields.yaml`, `matrix.yaml` written; `writeTemplateTwig()` copies the real `templates/_blocks/{entryType.handle}.twig` if it exists (see `13_TEMPLATE_ARCHITECTURE.md` §10 point 1 for the fallback behavior).
7. `PackageManagerService::discoverPackages()` → `installPackage()` → `enablePackage()`.
8. `SectionImportSourceRepository::record()` — records the 1:1 provenance row.
9. `Site7Studio::getInstance()->marketplace->syncDependencyRecords($record)`.
10. `ResourceImportedEvent` dispatched — this is what triggers `PackageBackupSubscriber` (`27_EVENTS_AND_HOOKS.md`), which is why a freshly-imported package already has one real, restorable version (`v1.0.0`) before any explicit "create version" action ever runs.

**`CraftSectionImportService::importFromSection(int $sectionId, array $entryTypeIds, array $meta)`**: for a Craft Section with multiple Entry Types, filters the requested `entryTypeIds` to those actually belonging to the Section, then calls `MatrixEntryTypeImportService::importFromEntryType()` once per Entry Type (each producing its own separate package), appending `" - {EntryType name}"` to the package name when more than one is selected. Also writes `importedFrom.sourceType = 'craft-section'` + `sourceSectionId`/`sourceSectionHandle` onto each resulting package's manifest, in addition to what `importFromEntryType()` already wrote — so provenance identifies both the native Section AND the specific Entry Type.

## 6. Important Classes

**`MatrixEntryTypeImportService`**
`src/services/import/MatrixEntryTypeImportService.php`
Responsibility: single-Entry-Type import; also the home of `captureOwnedFiles()`/`syncOwnedFilesFromLiveSource()`/`copyTemplateTwigFromLiveSource()` — shared by both import and sync.
Important methods: `importFromEntryType()`, `detectFields()` (public, reused by `SectionUpdateService`), `writeFieldsYaml()`/`writeMatrixYaml()` (public, reused by sync).
Called by: `ResourceImportController::actionImportSection()`, `CraftSectionImportService`.

**`CraftSectionImportService`**
`src/services/import/CraftSectionImportService.php`
Responsibility: multi-Entry-Type Section import, delegating per-type.

**`ResourceClassifierService`**
`src/services/import/ResourceClassifierService.php`
Responsibility: the field-level classifier used by every import path (§4).

**`EntryTypeSourceHasher`**
`src/services/import/EntryTypeSourceHasher.php`
Responsibility: structural update-detection hash.

**`SectionImportSourceRepository`**
`src/repositories/SectionImportSourceRepository.php`
Responsibility: `site7_section_import_sources` CRUD.

## 7. Data Model

`site7_section_import_sources` (1:1 with `site7_packages`, keyed by `sourceUid` unique + `packageId` unique). See `05_DATABASE_ARCHITECTURE.md`.

## 8. Filesystem Impact

**Created**: `packages/{handle}/manifest.json,fields.yaml,matrix.yaml,template.twig,README.md,preview/preview-data.yaml`.
**Never touched**: the live `templates/_blocks/{entryType.handle}.twig` — the copy is strictly read-only against the source.

## 9. Events

`ResourceImportedEvent` — see §5 point 10 and `27_EVENTS_AND_HOOKS.md`.

## 10. Validation and Safety

Duplicate-import guard (§5 point 2); handle collision auto-suffix, never a hard failure; `ResourceImportValidator` turns "nothing capturable at all" into the one hard error that blocks import.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Entry Type already imported | Throws with the existing package's handle |
| Entry Type has zero capturable fields | `ResourceImportValidator` blocks with a hard error |
| No real `_blocks/*.twig` file exists | Generic stub written instead (never a fatal error) |
| Proposed handle collides with an existing package | Auto-suffixed (e.g. `-2`), never fails |

## 12. Developer Change Guide

If changing what gets captured: modify `ResourceClassifierService::classifyField()`'s classification rules — do not duplicate classification logic inside the import service itself.

If changing Twig capture behavior: modify `copyTemplateTwigFromLiveSource()` — it's shared verbatim with Sync From Source (`18_SYNC_FROM_SOURCE.md`); a change here affects both.

## 13. Related Features

`13_TEMPLATE_ARCHITECTURE.md`, `18_SYNC_FROM_SOURCE.md`, `21_FRONTEND_FILE_OWNERSHIP.md` (owned-file selection happens at this same import step), `26_BACKUP_AND_RECOVERY.md`.

## 14. Known Limitations

None confirmed beyond the general Section-package "one template file" assumption noted in `13_TEMPLATE_ARCHITECTURE.md`.

## 15. Section package format v2 (`schema.json`, 2026-10-02)

**Why:** format v1 (`fields.yaml`/`matrix.yaml`) is a simplified copy - field handles, seven writable field types, one plain tab. Real blocks lost their per-layout field handles (Craft 5 lets a layout rename a global field; v1 created a *new* field per renamed handle - 28 duplicates on rp-craft), conditions, other field types (Assets, Table, plugin fields became PlainText), nested blocks and UIDs. Measured on rp-craft's `matrixContent`: 175 field placements, 47 renamed, 20 with conditions, 63 nested Matrix fields.

**What:** `SectionSchemaService::capture()` writes `schema.json` next to the v1 files: the block's entry type, every field its layout uses and - recursively - the entry types of nested Matrix fields and their fields, each as Craft's own project config with its UID, in dependency order (`items[]`: `kind` = `fields`|`entryTypes`, `uid`, `handle`, `config`). Dependencies come from `fieldUid` in layouts and a Matrix field's `settings.entryTypes` (`SectionSchemaService::references()`, unit-tested).

**Install** (`CraftResourceService::generateResources()` uses `schema.json` when present, v1 files otherwise): `SectionSchemaService::plan()` matches every item by UID - existing ones are **reused, never modified**; a different field/entry type with the same handle, or a field type whose plugin is missing, stops the install before anything changes. Missing items are applied with `ProjectConfig::set()` (Craft's own handlers, as when applying `config/project`) in dependency order, then re-applied with `force` so references inside cycles (blocks nesting each other) resolve. Only *created* UIDs are recorded as the package's resources, so uninstalling never deletes structure that existed before.

**Linking** a block into the page-builder field edits the field's own `settings.entryTypes` list (`SectionSchemaService::linkToMatrix()`), keeping the other entries' add-menu groups.

**Import/sync:** Import Existing Section writes `schema.json`; a block whose fields are all Shared Resources is now importable (v1 had nothing of its own to capture). Sync From Source regenerates it and treats any schema difference as a change.

**Bulk:** `php craft site7-studio/import/sections [--dry-run]` imports every block type of the configured page-builder field (Settings → General → Page Builder Field lets an authoring site use its own field, e.g. rp-craft's `matrixContent`).

**Verified 2026-10-02:**
- rp-craft: 27 of 27 `matrixContent` blocks imported (27 Sections, 17 Shared Resources); rp-craft unchanged - still 170 fields / 104 entry types, the only project config change is the plugin's `matrixFieldUid`, 12 content pages identical to a pre-import baseline.
- Fresh Craft with rp-craft's plugins and no structure: installing the 27 packages through `PackageManagerService` created 78 fields + 45 entry types, **123 of 123 identical** to rp-craft's project config, 90 per-layout handles kept, 27 `_blocks` templates; installing again created nothing.
