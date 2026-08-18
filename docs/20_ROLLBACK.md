# 20 — Rollback

## 1. Purpose

Document how a package is reverted to a previously recorded version, reusing the same infrastructure as Update rather than a separate mechanism.

## 2. What It Does

`PackageRollbackService::rollback()` restores `packages/{handle}/` wholesale from an older version's archive, then applies the SAME three-way baseline/live/incoming logic to the installed-file side, treating the older version as if it were the "incoming" version.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
Target: an older site7_package_versions row (archivePath, checksum)
   ↓
PackageArchiveHelper::replaceDirectory() — packages/{handle}/ wholesale replace
   from the older archive (package SOURCE side — always safe, Site7-owned)
   ↓
PackageManagerService::updateInstalledFiles($package, $targetVersion = older version)
   ↓  reuses PackageUpdatePlanner::classify() — SAME six/seven-case table as
   ↓  a forward update, just with "incoming" = the older archive's content
   ↓
reconcileAlreadyMatchingConflicts() — rollback-specific refinement layered
   ON TOP of classify(), not inside it (see §10)
   ↓
NO new version created (§10, restated from 17_PACKAGE_VERSIONING.md)
```

## 5. Execution Flow

1. `PackageRollbackService::rollback($package, $targetVersionId)` — loads the target `site7_package_versions` row; validates its archive still exists.
2. `PackageArchiveHelper::replaceDirectory($packagesPath, $archivePath)` — extracts the older archive over `packages/{handle}/`, replacing it wholesale (this side is unconditionally safe — package source is Site7-owned, never developer-edited in place, `06_PACKAGE_ARCHITECTURE.md`).
3. `PackageManagerService::updateInstalledFiles($package, targetVersion: older)` — runs the identical `PackageUpdatePlanner` three-way logic as a forward update, just pointed at older content.
4. `reconcileAlreadyMatchingConflicts()` — a rollback-specific pass that re-examines anything `classify()` reported as `RESULT_CONFLICT`: if the live file's content already exactly matches the OLDER (target) version's content — e.g. the developer had already manually reverted it by hand — this is downgraded from conflict to already-satisfied, since forcing a "conflict" report for a file that already matches the rollback target would be a false positive specific to the rollback direction. This refinement is NOT inside `classify()` itself because `classify()` is direction-agnostic and shared with forward updates, where this exact condition doesn't apply.
5. Manifest's `version` field is set to the older version string. **No new `site7_package_versions` row is created.**

## 6. Important Classes

**`PackageRollbackService`**
`src/services/publishing/PackageRollbackService.php`
Important methods: `rollback()`, `reconcileAlreadyMatchingConflicts()`.
Called by: explicit CP "Rollback to this version" action.

**`PackageArchiveHelper::replaceDirectory()`** — `src/services/support/PackageArchiveHelper.php`.
**`PackageManagerService::updateInstalledFiles()`** — reused verbatim, see `19_UPDATE_AND_CONFLICT_HANDLING.md`.

## 7. Data Model

Reads an older `site7_package_versions` row; writes to `site7_installed_files` (baseline updates for successfully-applied files) exactly like a forward update. **Never writes a new `site7_package_versions` row.**

## 8. Filesystem Impact

**Replaced wholesale**: `packages/{handle}/` (package source side).
**Modified per three-way rules**: installed files (`templates/_blocks/*.twig`, owned-file targets) — identical safety guarantees as a forward update; a locally-modified live file is never blindly overwritten by rollback either.

## 9. Events

None dispatched directly.

## 10. Validation and Safety

**Rollback does NOT create a new version** — verified directly in code (`rollback()` never calls `VersionManagerService`/`recordVersion()`) and live-tested in Step 7.

**Version collision prevention**: because rollback restores an OLDER manifest version number without recording a version row, the NEXT genuine content change after a rollback must not recompute a version number that already exists in history. This is handled entirely by `VersionManagerService::createVersion() (internally resolveBumpBaseVersion())`'s bump-base-off-history fix (`17_PACKAGE_VERSIONING.md` §10) — rollback itself contains no version-collision logic; it relies on that fix being correct.

**Same file-safety guarantees as Update**: a locally-modified installed file is never silently overwritten during rollback — the identical `PackageUpdatePlanner` guarantees apply, refined only by `reconcileAlreadyMatchingConflicts()` (§5 point 4), which can only ever downgrade a conflict to a no-op, never upgrade a safe case to a destructive one.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Target version's archive file missing from disk | Rollback fails before any changes are made — validated up front |
| Live file was locally modified differently from both current and target versions | `RESULT_CONFLICT`, reported, file left untouched |
| Live file already matches the rollback target exactly | Downgraded from conflict to no-op by `reconcileAlreadyMatchingConflicts()` |
| Rollback immediately followed by a new content change | Version bump correctly continues from historical max, no collision (`17_PACKAGE_VERSIONING.md`) |

## 12. Developer Change Guide

If changing rollback behavior: prefer extending `reconcileAlreadyMatchingConflicts()` for rollback-specific refinements — do **not** modify `PackageUpdatePlanner::classify()` itself for a rollback-only concern, since that function is shared with forward updates and a rollback-specific change there would silently alter update behavior too.

## 13. Related Features

`17_PACKAGE_VERSIONING.md`, `19_UPDATE_AND_CONFLICT_HANDLING.md`, `06_PACKAGE_ARCHITECTURE.md`.

## 14. Known Limitations

None confirmed beyond what's already noted in `17_PACKAGE_VERSIONING.md`/`19_UPDATE_AND_CONFLICT_HANDLING.md`.
