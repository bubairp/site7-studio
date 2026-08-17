# 19 — Update and Conflict Handling

## 1. Purpose

Document the core three-way file-update decision engine — the single most safety-critical piece of logic in the plugin, since it is what stands between a package update and silently destroying a developer's local edits.

## 2. What It Does

`PackageUpdatePlanner::classify()` compares three states of a single file — BASELINE (what Site7 Studio last installed), LIVE (what's actually on disk now), and INCOMING (what the new package version would install) — and returns one of six/seven classifications that determine whether it's safe to auto-apply, must be skipped, or must be reported as a conflict.

## 3. Current Status

**Implemented.**

## 4. Architecture — the three-way model

```
                    BASELINE (site7_installed_files.checksum)
                   /                                          \
                  /                                            \
        same?  LIVE (current file on disk)          INCOMING (new package version's file)
                  \                                            /
                   \                                          /
                    compare LIVE vs BASELINE, INCOMING vs BASELINE
```

| LIVE == BASELINE? | INCOMING == BASELINE? | Result |
|---|---|---|
| yes | yes | `RESULT_UNCHANGED` — nothing to do |
| yes | no | `RESULT_SAFE_UPDATE` — apply incoming, no local edits to lose |
| no | yes | `RESULT_LOCAL_MODIFICATION` — developer edited it, package didn't change this file — leave alone |
| no | no | LIVE == INCOMING? → yes: already matches target, no-op / no: `RESULT_CONFLICT` — both sides changed, differently |
| LIVE missing, BASELINE existed | — | `RESULT_LOCAL_DELETION` (developer deleted it) or `RESULT_SAFE_REMOVAL` (package no longer ships it and it's unmodified) / `RESULT_REMOVAL_CONFLICT` (package no longer ships it but developer modified it) |

## 5. Execution Flow

1. `PackageUpdatePlanner::plan($package, $targetVersion)` — for every file the target version ships (Twig via `_blocks/` mapping, or owned files via `resolveArchiveEntryName()`), gathers BASELINE (`InstalledFileBaselineService::getBaseline()`), LIVE (read current file + checksum), INCOMING (read from the target version's archive + checksum).
2. `classify($baseline, $live, $incoming)` — pure function, the six/seven-case table above.
3. Caller (`PackageManagerService::updateInstalledFiles()`) iterates the plan:
   - `RESULT_SAFE_UPDATE`/`RESULT_UNCHANGED` → `applySafeFileUpdate()`, updates baseline.
   - `RESULT_SAFE_REMOVAL` → `applySafeFileRemoval()`, removes baseline row.
   - `RESULT_LOCAL_MODIFICATION`/`RESULT_LOCAL_DELETION`/`RESULT_CONFLICT`/`RESULT_REMOVAL_CONFLICT` → skipped, reported back to the caller/CP for manual resolution, **file untouched**.

## 6. Important Classes

**`PackageUpdatePlanner`**
`src/services/synchronization/PackageUpdatePlanner.php`
Important methods: `classify()` (pure, six/seven-case decision), `plan()`, `resolveIncomingChecksums()`, `resolveArchiveEntryName()` (Step 8.2 — generalizes targetPath→archive-entry mapping via the target archive's own bundled `manifest.json.ownedFiles`, plus the built-in `_blocks/*.twig` regex mapping).
Called by: `PackageManagerService::updateInstalledFiles()`, `PackageRollbackService::rollback()`.

**`PackageManagerService`**
`src/services/PackageManagerService.php`
Important methods: `updateInstalledFiles()` (orchestrates plan+apply), `applySafeFileUpdate()` (writes file, re-checksums, updates baseline — refactored Step 8.2 to call the shared `resolveArchiveEntryName()` resolver instead of duplicating the mapping), `applySafeFileRemoval()`.

## 7. Data Model

Reads `site7_installed_files` (baseline) and `site7_package_versions`/archive contents (incoming); writes back to `site7_installed_files` only for `RESULT_SAFE_UPDATE`/`RESULT_SAFE_REMOVAL`.

## 8. Filesystem Impact

**Modified**: only files classified `RESULT_SAFE_UPDATE`.
**Deleted**: only files classified `RESULT_SAFE_REMOVAL`.
**Never touched**: files classified `RESULT_LOCAL_MODIFICATION`, `RESULT_LOCAL_DELETION`, `RESULT_CONFLICT`, `RESULT_REMOVAL_CONFLICT` — this is the entire safety guarantee of the system.

## 9. Events

None dispatched directly.

## 10. Validation and Safety

**A locally-modified file is NEVER overwritten by a forward update** — this is the single load-bearing guarantee of the whole system, live-verified in Step 6 with a throwaway package: a hand-edited `_blocks/*.twig` file survived an update that shipped a different version of the same file, and was correctly reported as `RESULT_LOCAL_MODIFICATION` rather than silently replaced.

**Content-equal short-circuit**: if LIVE already equals INCOMING (both changed, but landed on the same content — e.g. a developer manually applied the same fix the package update would have made), this is treated as already-satisfied, not a conflict — avoids a false-positive conflict report.

**Checksum convention**: `PackageArchiveHelper::computeFileChecksum()` — sha256, identical to every other checksum in this plugin (§`06_PACKAGE_ARCHITECTURE.md`).

## 11. Failure Scenarios

| Scenario | `classify()` Result |
|---|---|
| File untouched since install, package didn't change it | `RESULT_UNCHANGED` |
| File untouched since install, package DID change it | `RESULT_SAFE_UPDATE` |
| Developer edited file, package didn't change it | `RESULT_LOCAL_MODIFICATION` |
| Developer edited file, package ALSO changed it, differently | `RESULT_CONFLICT` |
| Developer edited file, package changed it to the SAME content | Treated as already-satisfied (no-op) |
| Developer deleted the file, package didn't remove it from the manifest | `RESULT_LOCAL_DELETION` |
| Package no longer ships the file, developer never touched it | `RESULT_SAFE_REMOVAL` |
| Package no longer ships the file, developer modified it | `RESULT_REMOVAL_CONFLICT` |

## 12. Developer Change Guide

If you need a new file type to participate in update/rollback: it must have (a) a baseline recorded via `InstalledFileBaselineService::record()` at install time and (b) an entry in `resolveArchiveEntryName()`'s resolution logic. **Never** write a parallel comparison system — `resolveArchiveEntryName()` was specifically generalized in Step 8.2 so owned files could reuse this exact planner rather than duplicating it.

## 13. Related Features

`13_TEMPLATE_ARCHITECTURE.md`, `16_INSTALLED_FILE_BASELINE.md`, `20_ROLLBACK.md`, `21_FRONTEND_FILE_OWNERSHIP.md`.

## 14. Known Limitations

This is one of TWO parallel three-way-comparison systems in the plugin (see `01_ARCHITECTURE.md`) — the other, `SynchronizationPlanner` (`32_STARTER_KIT_SYSTEM.md`), operates at the whole-Starter-Kit native-resource level and is NOT merged with this one; they are deliberately separate because they solve different-shaped problems (single-file vs. whole-site native-resource state).
