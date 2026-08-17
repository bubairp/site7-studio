# 21 — Frontend File Ownership (Owned Files Model)

## 1. Purpose

Document the `ownedFiles` model (Step 8.1/8.2) — how a package explicitly declares non-Twig frontend files (CSS/JS/config/assets) it owns, as an alternative to filename-guessing.

## 2. What It Does

`PackageManifest::$ownedFiles` is an explicit array of `{sourcePath, targetPath, type}` entries. Each entry is a deliberate developer selection at import time (`FrontendToolingScanner::listCandidateFrontendFiles()` surfaces candidates; the developer picks which ones), never an automatic "any file matching this pattern" heuristic.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
DISCOVERY         FrontendToolingScanner::listCandidateFrontendFiles()
   ↓               scans the live site's frontend build output/config directories,
   ↓               returns candidate {path, type} entries — informational only
   ↓
SELECTION         Developer picks a subset via ResourceImportController::
   ↓               actionListFrontendFileCandidates() (CP), stored into the
   ↓               import request
   ↓
CAPTURE           MatrixEntryTypeImportService::captureOwnedFiles() (Step 8.1)
   ↓               copies each selected live file into packages/{handle}/owned/,
   ↓               writes {sourcePath, targetPath, type} into manifest.ownedFiles
   ↓
INSTALL           PackageManagerService::installOwnedFiles() (Step 8.2) — type-
   ↓               agnostic loop over manifest.ownedFiles, writes each to its
   ↓               targetPath, content-compare guarded like template install
   ↓
BASELINE          InstalledFileBaselineService::record() per owned file —
   ↓               identical mechanism as template baseline (16_INSTALLED_FILE_BASELINE.md)
   ↓
SYNC              MatrixEntryTypeImportService::syncOwnedFilesFromLiveSource()
   ↓               (Step 8.2) — re-reads live targetPath INTO the package's
   ↓               owned/ copy, same read-only-against-live directionality as Twig sync
   ↓
UPDATE/ROLLBACK   PackageUpdatePlanner::resolveArchiveEntryName() (Step 8.2) —
                   generalized to map any ownedFiles targetPath to its archive
                   entry, so owned files go through the IDENTICAL three-way
                   safety system as templates — not a separate mechanism
```

## 5. Execution Flow

See Architecture above — the full lifecycle mirrors Template Architecture (`13_TEMPLATE_ARCHITECTURE.md`) almost exactly, by design: Step 8.2's explicit goal was making owned files a first-class participant in the SAME systems (baseline, sync, update, rollback), not a parallel implementation.

## 6. Important Classes

**`PackageManifest`** — `$ownedFiles` property (Step 8.1). Each entry: `sourcePath` (within package), `targetPath` (on host site), `type` (`css`/`js`/`config`/`asset`/etc., informational).

**`FrontendToolingScanner`**
`src/services/FrontendToolingScanner.php`
Important methods: `listCandidateFrontendFiles()` (Step 8.1), `detect()`, `captureNpmDependencies()`.

**`MatrixEntryTypeImportService`**
Important methods: `captureOwnedFiles()` (Step 8.1), `syncOwnedFilesFromLiveSource()` (Step 8.2).

**`PackageManagerService`**
Important methods: `installOwnedFiles()` (Step 8.2, type-agnostic).

**`PackageUpdatePlanner`**
Important method: `resolveArchiveEntryName()` (Step 8.2) — generalizes the target-path→archive-entry mapping to cover both the built-in `_blocks/*.twig` regex case and arbitrary `manifest.ownedFiles` entries, reading the mapping from the TARGET ARCHIVE's own bundled `manifest.json` (so the mapping is always correct for the specific version being compared against, not just the current live manifest).

**`ResourceImportController`**
Important method: `actionListFrontendFileCandidates()` (Step 8.1).

## 7. Data Model

No new table — `ownedFiles` lives inside `manifest.json` (package-side, versioned with the package); the live-site side reuses `site7_installed_files` (same table as templates, `resourceHandle`/`targetPath`/`checksum` are already generic — zero schema change was needed for Step 8.1/8.2).

## 8. Filesystem Impact

**Created** (capture): `packages/{handle}/owned/*` — copies of selected live files.
**Created** (install): each `targetPath` on the host site, content-compare guarded.
**Modified** (sync): the package's own `owned/*` copies only, never the live targets.
**Modified** (update/rollback): live targets, only when `PackageUpdatePlanner::classify()` returns a safe result.

## 9. Events

None dispatched directly by owned-file operations.

## 10. Validation and Safety

**Why explicit, not filename-guessed**: the user's own directive (restated here as the design rationale actually implemented) — automatic pattern-matching (e.g., "any `.css` in `dist/`") risks silently capturing files that aren't meant to be package-managed, or missing files with unconventional names/locations. Explicit `{sourcePath, targetPath, type}` selection means every owned file is a deliberate decision made once, at import/capture time, by a human reviewing `FrontendToolingScanner`'s candidate list.

**Reuse over duplication (Step 8.2's core achievement)**: before Step 8.2, `applySafeFileUpdate()` had Twig-specific path-mapping logic; owned files could not participate in update/rollback at all. Step 8.2 generalized `resolveArchiveEntryName()` so BOTH cases (Twig regex mapping, and explicit `ownedFiles` mapping) are resolved by the same function, and refactored `applySafeFileUpdate()` to call it — confirmed via the 4 new tests added to `PackageUpdatePlannerTest.php` and live verification that multiple changed owned files in one sync still produce exactly one version.

**Single-version guarantee**: owned-file diffs are folded into `SectionUpdateService`'s combined diff/update decision (`18_SYNC_FROM_SOURCE.md`) — not a separate versioning path.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Selected candidate file no longer exists at capture time | Not specifically handled beyond the standard file-read failure — capture would fail for that entry |
| `targetPath` collides with a path already owned by a DIFFERENT package | Not explicitly guarded against — no cross-package ownership registry/lock found |
| Owned file locally modified before an update | `RESULT_LOCAL_MODIFICATION` via the shared planner — never overwritten, identical to template behavior |

## 12. Developer Change Guide

If adding owned-file support to a new package type: reuse `installOwnedFiles()`/`resolveArchiveEntryName()`/`InstalledFileBaselineService` as-is — this is the entire point of Step 8.2's generalization. Do not create a type-specific parallel mechanism.

## 13. Related Features

`13_TEMPLATE_ARCHITECTURE.md`, `16_INSTALLED_FILE_BASELINE.md`, `18_SYNC_FROM_SOURCE.md`, `19_UPDATE_AND_CONFLICT_HANDLING.md`, `22_FRONTEND_TOOLING_AND_ASSET_DETECTION.md`.

## 14. Known Limitations

No cross-package `targetPath` collision detection (see Failure Scenarios) — not confirmed to be exercised by any current code path, but not explicitly guarded against either.
