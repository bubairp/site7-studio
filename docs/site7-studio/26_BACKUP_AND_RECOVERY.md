# 26 — Backup and Recovery

## 1. Purpose

Document the automatic backup mechanism that gives every freshly-imported package a restorable version before any explicit "Create Version" action ever runs.

## 2. What It Does

`PackageBackupSubscriber` listens for `ResourceImportedEvent` (fired by every "Import Existing X" flow) and calls `PackageBackupService::backupToLocalRepository()`, which exports the package and stores it in the same `marketplace-repo/` folder the Local Marketplace repository reads from — keeping only the latest backup per package handle.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
Any "Import Existing X" flow (Section/Website/Page/Matrix Entry Type)
   ↓
ResourceImportedEvent dispatched
   ↓
PackageBackupSubscriber::onResourceImported()  (registered in ImportServiceProvider)
   ↓  for each $event->packageHandles
PackageBackupService::backupToLocalRepository($handle)
   ↓
PackageExportService::exportPackage($handle, true)
   ↓
glob("{handle}-*.s7pkg") in marketplace-repo/, unlink() any prior backup for
   THIS handle (matched via bundle-manifest.json rootHandle, not filename prefix)
   ↓
new backup written to storage/site7-studio/marketplace-repo/
   ↓
PackageVersionRecord::updateAll() repoints site7_package_versions.archivePath
   to the new location (export moved the file)
```

## 5. Execution Flow

1. Every "Import Existing X" service (`MatrixEntryTypeImportService`, `PageImportService`, `WebsiteImportService` — NOT `CraftSectionImportService` directly, which delegates to `MatrixEntryTypeImportService` per Entry Type and relies on ITS dispatch) dispatches `ResourceImportedEvent` at the end of a successful import.
2. `PackageBackupSubscriber::onResourceImported($event)` — registered via `ImportServiceProvider` (`$plugin->getService('eventDispatcher')->addSubscriber(new PackageBackupSubscriber())`), iterates `$event->packageHandles`.
3. For each handle: `PackageBackupService::backupToLocalRepository($handle)` — wraps `PackageExportService::exportPackage($handle, true)`. **On failure, logs via `Craft::warning()` and returns silently** — a backup failure never blocks the import it's attached to.
4. Before writing the new backup, globs `{handle}-*.s7pkg` in `marketplace-repo/`; for each match, reads its `bundle-manifest.json` and checks `rootHandle === $handle` via `rootHandleOf()` (avoids false-positive prefix collisions, e.g. `hero-banner` vs `hero-banner-2`) before `@unlink()`ing it.
5. Repoints the just-recorded `site7_package_versions.archivePath` row to the new location, since export physically moved the file.

## 6. Important Classes

**`PackageBackupService`** — `src/services/support/PackageBackupService.php`. Method: `backupToLocalRepository(string $handle): void`.
**`PackageBackupSubscriber`** — `src/events/subscribers/PackageBackupSubscriber.php`, implements `EventSubscriberInterface`. Method: `onResourceImported()`.
**`PackageExportService`** — `src/services/PackageExportService.php` (or `publishing/`, see `09_PACKAGE_BUILD_AND_EXPORT.md`).
**`PackageArchiveHelper`** — `src/services/support/PackageArchiveHelper.php` — `readEntry()` used to inspect `bundle-manifest.json` inside a zip without extracting to a temp dir.

## 7. Data Model

Updates `site7_package_versions.archivePath` only — no dedicated backup table.

## 8. Filesystem Impact

**Created**: `storage/site7-studio/marketplace-repo/{handle}-{version-or-timestamp}.s7pkg`.
**Deleted**: the PRIOR backup for the same handle (retention: latest-only per handle — confirmed directly in code, not accumulated history).

## 9. Events

Listens to: `ResourceImportedEvent` (`27_EVENTS_AND_HOOKS.md`). Dispatches: none itself.

## 10. Validation and Safety

**Latest-only retention is deliberate**, per the in-code comment: "Keeps only the latest backup per package handle: each new create/import replaces the previous one rather than accumulating an unbounded history." This means backup is NOT a version-history mechanism — that's what `site7_package_versions` + immutable `.s7pkg` archives already provide (`17_PACKAGE_VERSIONING.md`); backup exists purely so a freshly-imported package has at least ONE restorable artifact immediately, before any explicit version is ever created.

**Failure isolation**: a backup failure is caught and logged, never propagated — importing a resource must never fail because backup failed.

**Handle-collision-safe deletion**: matching prior backups by `rootHandle` read from inside the zip (not by filename prefix alone) prevents `hero-banner`'s backup from being mistakenly deleted when `hero-banner-2` is backed up.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Export fails during backup (e.g. disk full) | Logged via `Craft::warning()`, import still succeeds, no backup created |
| Two similarly-named handles (`hero-banner`, `hero-banner-2`) | Correctly distinguished via `rootHandle` check, not filename glob alone |
| `CraftSectionImportService` importing multiple Entry Types | Each per-Entry-Type import (via `MatrixEntryTypeImportService`) dispatches its own `ResourceImportedEvent`, so each resulting package gets its own backup independently |

## 12. Developer Change Guide

If you need backup history (not just latest): this service explicitly does NOT provide that — use `site7_package_versions` + explicit "Create Version" instead, which is immutable and never pruned. Do not modify `PackageBackupService`'s retention behavior without understanding this is deliberate.

## 13. Related Features

`10_PACKAGE_IMPORT.md`, `14_IMPORT_EXISTING_SECTION.md`, `17_PACKAGE_VERSIONING.md`, `23_MARKETPLACE_ARCHITECTURE.md` (shares the `marketplace-repo/` directory), `27_EVENTS_AND_HOOKS.md`.

## 14. Known Limitations

Backup is single-slot per handle — cannot be used to recover an import from two imports ago. This is by design, not an oversight (see §10), but is worth flagging for anyone expecting backup history.
