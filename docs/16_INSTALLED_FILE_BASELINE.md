# 16 — Installed File Baseline

## 1. Purpose

Explain the concept that makes safe updates/rollback possible: recording the checksum of a file exactly as it was installed, distinct from the package's own version checksum.

## 2. What It Does

`InstalledFileBaselineService` persists, per installed file, `(packageId, resourceHandle, targetPath, installedVersion, checksum, installedAt)` in `site7_installed_files`.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
PackageManagerService::installPackage() (template, after calling
  CraftResourceService::generateResources() — the baseline record() call
  itself lives in installPackage(), not in generateResources()) ─┐
PackageManagerService::installOwnedFiles() (owned files) ─┼──→ InstalledFileBaselineService::record()
PackageManagerService::applySafeFileUpdate() (safe update) ─┘         ↓
                                                              site7_installed_files row
                                                                       ↓
                                                    PackageUpdatePlanner::plan() reads it as "A"
```

## 5. Execution Flow

`record(int $packageId, string $resourceHandle, string $targetPath, string $installedVersion, string $checksum)` — finds an existing row by `(packageId, targetPath)` (the unique composite index) or creates a new one; sets/overwrites all fields; saves. This is a genuine **upsert** — reinstalling an unchanged file never accumulates a second row.

`getBaseline(int $packageId, string $targetPath): ?array` — single-row lookup.
`allForPackage(int $packageId): array` — every baseline row for a package (used by `PackageUpdatePlanner::plan()` to know which files to even consider).
`remove(int $packageId, string $targetPath): void` — deletes a single row (used only for a confirmed `RESULT_SAFE_REMOVAL`, `19_UPDATE_AND_CONFLICT_HANDLING.md`).

## 6. Important Classes

**`InstalledFileBaselineService`**
`src/services/synchronization/InstalledFileBaselineService.php`
Responsibility: sole writer/reader of `site7_installed_files`. Pure persistence — no comparison/diff logic of its own (that's `PackageUpdatePlanner`, a deliberate separation matching `SynchronizationPlanner`'s own architecture, `32_STARTER_KIT_SYSTEM.md`).
Important methods: `record()`, `getBaseline()`, `allForPackage()`, `remove()`.
Called by: `PackageManagerService` (write), `PackageUpdatePlanner` (read), `PackageRollbackService` (via `PackageManagerService::updateInstalledFiles()`).

**`PackageInstalledFileRecord`**
`src/records/PackageInstalledFileRecord.php`
Bare `ActiveRecord` for `site7_installed_files`.

## 7. Data Model

`site7_installed_files`: `id`, `packageId` (FK→`site7_packages.id` CASCADE/CASCADE), `resourceHandle`, `targetPath`, `installedVersion`, `checksum`, `installedAt`, `dateCreated`, `dateUpdated`. Unique composite index `(packageId, targetPath)`.

## 8. Filesystem Impact

None directly — this service never touches the filesystem itself; it only records checksums that OTHER services computed after actually writing files.

## 9. Events

None dispatched.

## 10. Validation and Safety

**Why baseline is different from package checksum**: the package checksum (`site7_package_versions.checksum`, `17_PACKAGE_VERSIONING.md`) describes the package's SOURCE state at a version — it says nothing about what's currently on the HOST SITE's disk. The baseline describes exactly that: the checksum of a specific installed file as it existed immediately after Site7 Studio last wrote it. Two files can have the identical package checksum but different baselines if the same package was installed on two sites at different times, or if a site's copy was later hand-edited. A two-way comparison (installed file vs. package's CURRENT state) cannot distinguish "the package changed since install" from "the developer edited the installed file" — both look identical. The baseline is the third fact that resolves this ambiguity — see `19_UPDATE_AND_CONFLICT_HANDLING.md` for the full three-way table this enables.

**Upsert semantics**: reinstalling the same file never accumulates a second row — verified live.

**Cascade on package delete**: `ON DELETE CASCADE` FK — no explicit cleanup code needed; confirmed by the FK definition and by live testing showing zero manual deletion calls are required when a package is permanently deleted.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| `record()` called for a file that was never actually written | Baseline would be WRONG (describes a file that doesn't exist) — this cannot happen in current code because every caller only calls `record()` AFTER verifying the write succeeded (`PackageManagerService::applySafeFileUpdate()` explicitly checksums the just-written file before recording) |
| Package deleted while baseline rows exist | Cascade-deleted automatically |
| `getBaseline()` for an untracked file | Returns `null` — callers must handle this (e.g. `PackageUpdatePlanner` treats "no baseline" as out of scope, not an error) |

## 12. Developer Change Guide

If you need to track a new kind of installed file: call `InstalledFileBaselineService::record()` after the write succeeds — do NOT create a second baseline table or service. The schema is already generic (`targetPath`/`resourceHandle`/`checksum` are not Twig-specific) — proven by Step 8.1/8.2 adding owned-file support with zero schema change.

## 13. Related Features

`13_TEMPLATE_ARCHITECTURE.md`, `19_UPDATE_AND_CONFLICT_HANDLING.md`, `20_ROLLBACK.md`, `21_FRONTEND_FILE_OWNERSHIP.md`.

## 14. Known Limitations

No index exists for querying baselines across packages by `targetPath` alone (only the composite unique index) — not currently needed by any code path.
