# 05 — Database Architecture

## 1. Purpose

Explain the complete SITE7 Studio database schema, how the 16 tables relate, and which service owns writes to each. This is the narrative companion to `37_DATABASE_TABLE_REFERENCE.md`'s flat lookup table — read this document for relationships and lifecycle, that one for a quick per-table lookup.

## 2. What It Does

16 tables (all prefixed `site7_`, Craft's `{{%...}}` table-prefix convention applies to all), created across 15 timestamped migrations + one no-op `Install.php` stub. (§7's table below always had all 16 listed — the "15" in earlier prose here was an off-by-one against its own reference table.)

## 3. Current Status

**Implemented.** Schema confirmed directly against every migration file, not assumed.

## 4. Architecture — relationship diagram

```
site7_packages (1) ──┬──< site7_components            (Section/Component CP metadata)
                      ├──< site7_templates              (Template package CP metadata)
                      ├──< site7_package_dependencies    (forward edges: package→package, package→sharedResource)
                      ├──< site7_package_versions        (IMMUTABLE version history)
                      ├──< site7_package_publications     (publish-attempt history)
                      ├──< site7_section_import_sources   (1:1 - Import Existing Section provenance)
                      ├──< site7_page_import_sources      (1:1 - Import Existing Page provenance)
                      ├──< site7_website_import_sources   (1:1 - Import Existing Website provenance)
                      └──< site7_installed_files          (installed-file baseline)

site7_shared_resources (1) ──< site7_shared_resource_dependencies  (Shared→Shared forward edges)

site7_installed_starter_kits   (standalone - whole-Blueprint baseline per installed Starter Kit)
site7_sync_history             (standalone - one row per Starter Kit sync attempt)
site7_sync_sessions            (standalone - cross-process sync session state)
site7_install_sessions         (standalone - cross-process install session state)
```

All FKs to `site7_packages.id` use **ON DELETE CASCADE, ON UPDATE CASCADE** — deleting a package's row automatically removes every version, dependency, source-tracking, and baseline row for it, with zero application cleanup code (see `12_PACKAGE_UNINSTALLATION.md`).

## 5. Execution Flow (how a row's lifecycle typically runs)

Example, `site7_package_versions`:
1. `PackageExportService::exportPackage()` computes a real checksum and writes a real `.s7pkg`.
2. `MarketplaceService::recordVersion($record, $checksum, $archivePath)` — the ONE writer. Checks for an existing row `(packageId, version)` first (dedup guard); inserts only if none found.
3. Row is read by `VersionManagerService::getVersionHistory()`, `PackageUpdatePlanner` (via `PackageManagerService::updateInstalledFiles()`), `PackageRollbackService`, `LibraryController`.
4. Row is never updated after creation (immutability — see `17_PACKAGE_VERSIONING.md`), except `archivePath` being repointed by `PackageBackupService` when the *same bytes* physically move (`26_BACKUP_AND_RECOVERY.md`).
5. Row is deleted only via the `ON DELETE CASCADE` when the parent package is permanently deleted.

## 6. Important Classes

Every table has exactly one (mostly bare) `ActiveRecord` in `src/records/`. See `37_DATABASE_TABLE_REFERENCE.md`'s "Record" column for the full mapping, and the note below on the one record with extra logic.

`PackageRecord` (`src/records/PackageRecord.php`) is the one record with real logic beyond `tableName()`: a private `$_manifest` cache and `getManifest(): ?PackageManifest`, which resolves the package's on-disk path via `PackageManagerService` and reads it with `PackageReader`.

## 7. Data Model — full table reference

| Table | Purpose | Key columns | Unique/FK |
|---|---|---|---|
| `site7_packages` | Package registry | `handle`, `type`, `version`, `status`, `authoringStatus`, `category`, `tags`, `creatorId`, `entitlementRemovableOn` | `handle` unique; `creatorId` FK→`users.id` ON DELETE SET NULL |
| `site7_components` | Section-package CP metadata | `packageId`, `matrixEntryTypeHandle`, `enabled` | FK→packages CASCADE |
| `site7_templates` | Template-package CP metadata | `packageId`, `templateCategory`, `homepage` | FK→packages CASCADE |
| `site7_package_dependencies` | Forward edges: package→package, package→sharedResource | `packageId`, `dependencyType`, `dependencyHandle`, `minimumVersion` (unenforced) | FK→packages CASCADE |
| `site7_package_versions` | Immutable version history | `packageId`, `version`, `checksum`, `archivePath`, `releaseNotes`, `releaseDate` | FK→packages CASCADE |
| `site7_package_publications` | One row per publish attempt | `packageId`, `repositoryHandle`, `version`, `status`, `signature` (reserved, unused) | FK→packages CASCADE |
| `site7_section_import_sources` | Import Existing Section provenance + update-hash | `packageId` (unique), `sourceUid` (unique), `sourceHash` | FK→packages CASCADE |
| `site7_page_import_sources` | Import Existing Page provenance | `packageId` (unique), `sourceUid` (unique), `sourceHash` | FK→packages CASCADE |
| `site7_website_import_sources` | Import Existing Website provenance (keyed by computed `selectionKey`) | `packageId` (unique), `selectionKey` (unique), `sourceEntryUids` | FK→packages CASCADE |
| `site7_installed_files` | Installed-file baseline checksum | `packageId`, `resourceHandle`, `targetPath`, `installedVersion`, `checksum` | composite `(packageId, targetPath)` unique; FK→packages CASCADE |
| `site7_shared_resources` | Shared Resource registry | `handle` (unique), `type`, `craftUid`, `craftId`, `installStatus` | `handle` unique |
| `site7_shared_resource_dependencies` | Shared→Shared edges | `sharedResourceId`, `dependsOnHandle` | FK→shared_resources CASCADE |
| `site7_install_sessions` | Starter Kit install cross-process session state | `uid` (unique), `starterKitHandle`, `status`, `data` (mediumtext JSON) | `uid` unique |
| `site7_installed_starter_kits` | Whole-Blueprint sync baseline | `handle` (unique), `installedVersion`, `blueprintSnapshot` (mediumtext JSON) | `handle` unique |
| `site7_sync_history` | Starter Kit sync attempt history | `handle`, `fromVersion`, `toVersion`, `status`, `report` (mediumtext JSON) | — |
| `site7_sync_sessions` | Starter Kit sync cross-process session state | `uid` (unique), `handle`, `status`, `data` (mediumtext JSON) | `uid` unique |

Full per-migration column detail: `37_DATABASE_TABLE_REFERENCE.md`.

## 8. Filesystem Impact

Not applicable — this document is database-only.

## 9. Events

None fired directly by the schema layer.

## 10. Validation and Safety

Every FK to `site7_packages.id` cascades on delete — this is the structural mechanism underlying "delete a package and everything it owns disappears with it" (`12_PACKAGE_UNINSTALLATION.md`). No orphaned rows are possible for any of the 9 tables with a `packageId` FK.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Insert into a version-history-style table for an already-existing key | Dedup guard in the owning service (`MarketplaceService::recordVersion()`) — silently returns, no duplicate |
| Package deleted while versions/baselines/sources exist | All cascade-deleted automatically via FK |
| `site7_installed_files` insert for an already-tracked `(packageId, targetPath)` | Upsert — `InstalledFileBaselineService::record()` updates the existing row |

## 12. Developer Change Guide

If adding a new table: mirror the existing migration docblock convention (state *why*, not just *what*), guard with `tableExists()`, add the matching bare `ActiveRecord`, and if the table needs cascade-delete-with-a-package semantics, add the FK with `ON DELETE CASCADE, ON UPDATE CASCADE` exactly like every existing `packageId` column.

If adding a column: prefer a nullable/defaulted `alterColumn`/`addColumn` migration over a schema-breaking change — every existing addition to `site7_packages` (authoringStatus, creatorId, entitlementRemovableOn, category, tags) followed this pattern with a backfill where needed.

## 13. Related Features

`37_DATABASE_TABLE_REFERENCE.md`, `06_PACKAGE_ARCHITECTURE.md`, `16_INSTALLED_FILE_BASELINE.md`, `17_PACKAGE_VERSIONING.md`.

## 14. Known Limitations

- `site7_package_dependencies.minimumVersion` exists but is not enforced by any resolver.
- No index exists to make "find all installed files across all packages for a given targetPath prefix" efficient — not currently needed by any code path, but worth knowing if building a bulk-query feature later.
