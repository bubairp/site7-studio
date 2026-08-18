# 17 — Package Versioning

## 1. Purpose

Document how version numbers are computed, how immutable archives are created and stored, and how rollback avoids version collisions.

## 2. What It Does

`VersionManagerService` computes the next semantic version; `PackageExportService`/`PackageArchiveHelper` build the actual `.s7pkg` zip; `MarketplaceService::recordVersion()` writes the dedup-safe `site7_package_versions` row.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
Any content-changing operation (explicit "Create Version", Sync From Source,
update-in-place, package edit)
   ↓
VersionManagerService::createVersion(package, bumpType)
   ↓  calls private resolveBumpBaseVersion(record):
   ↓  base = max(manifest.version, MAX(site7_package_versions.version) for this package)
   ↓  (NOT manifest version alone — the Step 7 fix, see §10)
   ↓
semver bump (patch/minor/major)
   ↓
PackageExportService::exportPackage() → PackageArchiveHelper → real .s7pkg zip,
   sha256 checksum
   ↓
MarketplaceService::recordVersion() → site7_package_versions row
   (dedup-safe: same packageId+version is a no-op, never a duplicate row)
```

## 5. Execution Flow

1. Caller determines a content change occurred (diff-based — never version on a no-op, `18_SYNC_FROM_SOURCE.md`/`19_UPDATE_AND_CONFLICT_HANDLING.md`).
2. `VersionManagerService::createVersion($package, $bumpType = 'patch')` calls the private `resolveBumpBaseVersion($record)`:
   - iterates all `site7_package_versions` (via `PackageVersionRecord::find()`) rows for this `packageId`, keeping the highest via `version_compare()` — the historical max (or manifest version if no rows exist yet).
   - `$base = max($package->manifest->version, $historicalMax)`.
   - Bump `$base` by `$bumpType`.
3. `PackageExportService::exportPackage()` — builds the zip via `PackageArchiveHelper::createArchive()`, computes checksum via `computeDirectoryChecksum()`.
4. `MarketplaceService::recordVersion($packageId, $version, $archivePath, $checksum, $releaseNotes)` — checked against `(packageId, version)` uniqueness before insert.
5. Manifest's own `version` field is updated to match.

## 6. Important Classes

**`VersionManagerService`**
`src/services/publishing/VersionManagerService.php`
Important methods: `createVersion()` (public), `resolveBumpBaseVersion()` (private — the bump-base logic).
Called by: `SectionUpdateService`, explicit "Create Version" CP action, `StarterKitSyncService`. **NOT called by `PageUpdateService`** — Page-package sync bypasses this service entirely and constructs a `PackageVersionRecord` directly; see `18_SYNC_FROM_SOURCE.md` §5a for the resulting gaps (no version bump, no `archivePath`, non-standard checksum).

**`PackageExportService`** — `src/services/publishing/PackageExportService.php`. See `09_PACKAGE_BUILD_AND_EXPORT.md`.
**`PackageArchiveHelper`** — `src/services/support/PackageArchiveHelper.php`. `createArchive()`, `computeDirectoryChecksum()`, `computeFileChecksum()`, `replaceDirectory()`.
**`MarketplaceService`** — `src/services/MarketplaceService.php`. `recordVersion()`.

## 7. Data Model

`site7_package_versions`: `id`, `packageId` (FK CASCADE), `version`, `archivePath`, `checksum`, `releaseNotes`, `releaseDate`, `dateCreated`. **`(packageId, version)` uniqueness is application-level only** (checked in `MarketplaceService::recordVersion()` before insert) — there is no DB unique index on this pair (`src/migrations/m260716_100535_create_package_tables.php` creates no such index, unlike `site7_packages.handle` or `site7_installed_files`'s composite index, which are real DB constraints). This matters for the race-condition caveat in §11 and for `PageUpdateService`'s bypass path (`18_SYNC_FROM_SOURCE.md` §5a), which writes duplicate `(packageId, version)` rows precisely because nothing at the DB layer stops it.

## 8. Filesystem Impact

**Created**: one `.s7pkg` zip file per version, under `storage/site7-studio/exports/` or `marketplace-repo/` depending on caller (`09_PACKAGE_BUILD_AND_EXPORT.md`).
**Never deleted** by normal version-creation flow.

## 9. Events

`createVersion()` dispatches `VersionCreatedEvent` directly, after the version row and archive are confirmed to exist (`VersionManagerService.php:83-86`) — this is a direct dispatch inside the method, not something left to a caller.

## 10. Validation and Safety

**The bump-base fix (Step 7)**: originally, the bump-base logic (now `resolveBumpBaseVersion()`) bumped off the manifest's `version` field alone. This collided after a rollback: rollback restores an OLDER manifest (with an older version number) without creating a new version row (§ below); the next legitimate content change would then bump from that OLD number, potentially recreating a version string that already exists in `site7_package_versions` history. The fix computes the base as `max(manifest version, highest version ever recorded for this package)`, so a post-rollback version bump always continues from the true historical maximum, never colliding. Live-verified during Step 7.

**Rollback does NOT create a new version** — restated here because it directly interacts with this document: `PackageRollbackService::rollback()` never calls `VersionManagerService`/`recordVersion()`. Only the NEXT genuine content change creates a version, and the fix above ensures it doesn't collide.

**Dedup-safe recording**: `recordVersion()` checks `(packageId, version)` before insert — calling it twice with identical arguments is a no-op, not a duplicate-row error.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Two content changes race to version at once | Not specifically guarded against — no locking found, and no DB unique constraint on `(packageId, version)` to fall back on (see §7). Two concurrent requests computing the same target version could both insert, producing a real duplicate row — this is a genuine open gap, not one softened by a DB-level safety net. |
| `recordVersion()` called with a version that already exists | No-op — treated as already-recorded |
| Rollback followed immediately by a content change | Handled correctly by the Step 7 fix — verified live |

## 12. Developer Change Guide

If you need version numbers computed anywhere: call `VersionManagerService::createVersion()` — never hand-roll semver math or read `manifest.version` directly as the bump base, that reintroduces the exact bug Step 7 fixed in `resolveBumpBaseVersion()`.

## 13. Related Features

`09_PACKAGE_BUILD_AND_EXPORT.md`, `18_SYNC_FROM_SOURCE.md`, `19_UPDATE_AND_CONFLICT_HANDLING.md`, `20_ROLLBACK.md`, `26_BACKUP_AND_RECOVERY.md`.

## 14. Known Limitations

No explicit locking around concurrent version creation for the same package (see Failure Scenarios).
