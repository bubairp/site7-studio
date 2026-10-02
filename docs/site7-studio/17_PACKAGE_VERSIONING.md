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
Called by: `SectionUpdateService`, `PageUpdateService`, explicit "Create Version" CP action, `StarterKitSyncService`.

**`PackageExportService`** — `src/services/publishing/PackageExportService.php`. See `09_PACKAGE_BUILD_AND_EXPORT.md`.
**`PackageArchiveHelper`** — `src/helpers/PackageArchiveHelper.php`. `createArchive()`, `computeDirectoryChecksum()`, `computeFileChecksum()`, `replaceDirectory()`.
**`MarketplaceService`** — `src/services/MarketplaceService.php`. `recordVersion()`.

## 7. Data Model

`site7_package_versions`: `id`, `packageId` (FK CASCADE), `version`, `archivePath`, `checksum`, `releaseNotes`, `dateCreated`. Unique composite `(packageId, version)`.

## 8. Filesystem Impact

**Created**: one `.s7pkg` zip file per version, under `storage/site7-studio/exports/` or `marketplace-repo/` depending on caller (`09_PACKAGE_BUILD_AND_EXPORT.md`).
**Never deleted** by normal version-creation flow.

## 9. Events

None dispatched directly by `VersionManagerService` itself (callers like `ResourceImportedEvent` subscribers trigger it, `27_EVENTS_AND_HOOKS.md`).

## 10. Validation and Safety

**The bump-base fix (Step 7)**: originally, the bump-base logic (now `resolveBumpBaseVersion()`) bumped off the manifest's `version` field alone. This collided after a rollback: rollback restores an OLDER manifest (with an older version number) without creating a new version row (§ below); the next legitimate content change would then bump from that OLD number, potentially recreating a version string that already exists in `site7_package_versions` history. The fix computes the base as `max(manifest version, highest version ever recorded for this package)`, so a post-rollback version bump always continues from the true historical maximum, never colliding. Live-verified during Step 7.

**Rollback does NOT create a new version** — restated here because it directly interacts with this document: `PackageRollbackService::rollback()` never calls `VersionManagerService`/`recordVersion()`. Only the NEXT genuine content change creates a version, and the fix above ensures it doesn't collide.

**Dedup-safe recording**: `recordVersion()` checks `(packageId, version)` before insert — calling it twice with identical arguments is a no-op, not a duplicate-row error.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Two content changes race to version at once | Not specifically guarded against — no locking found; the composite unique constraint would surface as a DB error only in the (unhandled) case both compute an identical target version simultaneously, effectively covered by normal request serialization |
| `recordVersion()` called with a version that already exists | No-op — treated as already-recorded |
| Rollback followed immediately by a content change | Handled correctly by the Step 7 fix — verified live |

## 12. Developer Change Guide

If you need version numbers computed anywhere: call `VersionManagerService::createVersion()` — never hand-roll semver math or read `manifest.version` directly as the bump base, that reintroduces the exact bug Step 7 fixed in `resolveBumpBaseVersion()`.

## 13. Related Features

`09_PACKAGE_BUILD_AND_EXPORT.md`, `18_SYNC_FROM_SOURCE.md`, `19_UPDATE_AND_CONFLICT_HANDLING.md`, `20_ROLLBACK.md`, `26_BACKUP_AND_RECOVERY.md`.

## 14. Known Limitations

No explicit locking around concurrent version creation for the same package (see Failure Scenarios).
