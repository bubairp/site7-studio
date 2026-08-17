# 18 — Sync From Source

## 1. Purpose

Document how a Section/Page package's stored content is refreshed from its live Craft origin, and why this is the ONLY direction sync flows (never live→package→live in one action).

## 2. What It Does

`SectionUpdateService::diff()`/`updateInPlace()` (Section packages) and `PageUpdateService::diff()`/`updateInPlace()` (Page packages) re-read the live Craft resource the package was originally imported from, compare it against the package's stored copy, and — only if different — overwrite the package's stored copy and create exactly one new version.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
Live Craft Entry Type (Section package) / Entry (Page package)
   ↓
diff(): detectFields() + diffTwig() [Section] / field-value diff [Page]
   ↓
   ├── no differences → no-op, no version created
   └── differences found →
         updateInPlace():
           overwrite package's fields.yaml/matrix.yaml/template.twig (or field-value store)
           ↓
           VersionManagerService::createVersion() (bump base resolved via its private resolveBumpBaseVersion()) → exactly ONE new version,
           even if multiple things changed (fields AND Twig AND owned files)
```

## 5. Execution Flow

1. `SectionUpdateService::diff($package)`:
   - `detectFields($entryType)` (reused verbatim from `MatrixEntryTypeImportService`) vs. package's stored `fields.yaml`.
   - `diffTwig()` — live `_blocks/{handle}.twig` checksum vs. package's `template.twig` checksum (`PackageArchiveHelper::computeFileChecksum()`).
   - `diffOwnedFiles()` (Step 8.2) — for each `manifest.ownedFiles` entry, live target file checksum vs. package's stored source-file checksum.
   - Returns a structured diff result; caller decides whether to proceed.
2. `updateInPlace($package)` — only called if `diff()` reported changes:
   - Rewrites `fields.yaml`/`matrix.yaml` if fields changed.
   - `copyTemplateTwigFromLiveSource()` (shared with import) if Twig changed.
   - `syncOwnedFilesFromLiveSource()` (Step 8.2) if any owned file changed.
   - All three checked together — **one combined "did anything change" decision**, so multiple simultaneous changes still produce exactly one version, never one-per-file.
   - `VersionManagerService::createVersion()` + `recordVersion()`.

`PageUpdateService` mirrors this shape for a single Entry's field values (no Twig/owned-file concept for a page).

## 6. Important Classes

**`SectionUpdateService`**
`src/services/import/SectionUpdateService.php`
Important methods: `diff()`, `updateInPlace()`, `diffTwig()`, `diffOwnedFiles()` (added Step 8.2).
Called by: `ResourceImportController::actionSyncSection()` (or equivalent CP action).

**`PageUpdateService`** — `src/services/import/PageUpdateService.php`. `diff()`, `updateInPlace()`.

## 7. Data Model

No dedicated table — reads/writes `site7_packages`/`site7_package_versions` (via `VersionManagerService`) and the package's own files on disk.

## 8. Filesystem Impact

**Modified**: `packages/{handle}/fields.yaml,matrix.yaml,template.twig`, and any owned-file source paths — ONLY the package's own stored copies.
**Never touched**: the live `templates/_blocks/*.twig` or any installed owned-file target — sync reads FROM the live site, never writes TO it. Writing to the live site only happens later, via a separate explicit Update action (`19_UPDATE_AND_CONFLICT_HANDLING.md`).

## 9. Events

None dispatched directly by sync (distinct from import, which dispatches `ResourceImportedEvent`).

## 10. Validation and Safety

**No-op guarantee**: `diff()` returning no changes means `updateInPlace()` is never called — verified live: syncing an untouched Entry Type produces zero new version rows.

**Single-version guarantee for multi-file changes** (Step 8.2 addition): before Step 8.2, only fields+Twig were part of the combined decision; owned files were bolted on afterward but folded into the SAME `diff()`/`updateInPlace()` decision rather than a parallel one, so a Section with both a Twig edit and a CSS edit still produces one version, not two — live-verified.

**Directionality**: sync is strictly read-from-live, write-to-package. It never writes to the live site. This is a deliberate asymmetry with Update (`19_UPDATE_AND_CONFLICT_HANDLING.md`), which is write-to-live, and exists specifically so a developer's live edits are never silently lost by a sync action — only an explicit Update can push changes live, and even then only through the three-way conflict check.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Live Entry Type was deleted since import | `diff()`/`updateInPlace()` would fail loading the source — no specific graceful-degradation code found beyond the standard not-found exception |
| Live `_blocks/*.twig` file deleted since import | `diffTwig()` treats this as a difference (empty/missing vs. stored content) — surfaces as a detected change |
| Sync called on a package with no `SectionImportSourceRepository` provenance row | Not directly supported — sync depends on knowing the live source, which only exists for imported (not hand-authored) packages |

## 12. Developer Change Guide

If adding a new syncable file type: add its diff to `SectionUpdateService::diff()`'s combined decision (mirror `diffOwnedFiles()`'s pattern) — do not create a separate sync action/version per file type.

## 13. Related Features

`13_TEMPLATE_ARCHITECTURE.md`, `14_IMPORT_EXISTING_SECTION.md`, `17_PACKAGE_VERSIONING.md`, `19_UPDATE_AND_CONFLICT_HANDLING.md`, `21_FRONTEND_FILE_OWNERSHIP.md`.

## 14. Known Limitations

Sync depends on the original import provenance row still existing and the live source still existing — no fallback if either was removed.
