# 31 — Security and Validation

## 1. Purpose

Consolidate the plugin's validation and safety mechanisms that are otherwise documented per-feature — a single reference for "what stops something dangerous from happening."

## 2. What It Does

Aggregates: checksum verification, three-way conflict detection, usage-checked deletion, Dev-Mode gating, duplicate-import guards, and the current (stub) state of package signing.

## 3. Current Status

**Implemented** for all mechanisms except cryptographic package signing (explicit stub, `24_LICENSING_AND_COMMERCE.md`).

## 4. Architecture — safety mechanisms by layer

```
FILE INTEGRITY        PackageArchiveHelper::computeFileChecksum()/computeDirectoryChecksum()
                       sha256, used identically everywhere (baseline, version, three-way compare)

FILE SAFETY            PackageUpdatePlanner::classify() — three-way baseline/live/incoming,
                       never overwrites a locally-modified file (19_UPDATE_AND_CONFLICT_HANDLING.md)

RESOURCE DELETION      CraftResourceService::removePackageResources() — usage-checked via
                       Entry::find()->typeId()->count() / findFieldUsages() (12_PACKAGE_UNINSTALLATION.md)

DESTRUCTIVE-OP GATING  deletePackage()/detachPackage() — Dev-Mode-gated (with a self-captured-
                       Template exception for deletePackage only). installPackage()/enablePackage()/
                       disablePackage()/removePackage() have NEITHER a permission check NOR a
                       Dev-Mode gate — PackageActionController calls requirePermission() nowhere in
                       the file (see 28_CONTROLLERS_AND_ROUTES.md §10). Any CP user with general CP
                       access can install/enable/disable/remove packages today.

DUPLICATE PREVENTION   SectionImportSourceRepository/PageImportSourceRepository/
                       WebsiteImportSourceRepository — 1:1 uniqueness guards at import time

VERSION INTEGRITY      (packageId, version) uniqueness is application-level only in
                       recordVersion() — NOT a DB constraint (17_PACKAGE_VERSIONING.md §7);
                       dedup-safe against duplicate calls with identical args, but not against
                       a genuine race. VersionManagerService bump-base-off-history fix prevents
                       post-rollback collisions. PageUpdateService bypasses recordVersion()
                       entirely and can write real duplicate rows (18_SYNC_FROM_SOURCE.md §5a).

PACKAGE AUTHENTICITY   PackageSignerInterface / NullPackageSigner — architecture prepared,
                       NOT cryptographically enforced (verify() always true)
```

## 5. Execution Flow

Not a single flow — see each mechanism's own document for its execution path.

## 6. Important Classes

`PackageArchiveHelper`, `PackageUpdatePlanner`, `CraftResourceService`, `PackageManagerService`, `SectionImportSourceRepository`/`PageImportSourceRepository`/`WebsiteImportSourceRepository`, `VersionManagerService`, `NullPackageSigner`.

## 7. Data Model

Not applicable at this document's scope — see individual feature documents.

## 8. Filesystem Impact

Not applicable at this document's scope.

## 9. Events

Not applicable at this document's scope.

## 10. Validation and Safety — consolidated statement

The plugin's single most repeated safety guarantee, restated once more here as the canonical statement: **a file the developer has modified on the live site is never silently overwritten by any Site7 Studio operation** (install re-run, update, sync, rollback) — every one of those operations routes through the same `PackageUpdatePlanner::classify()` three-way check before writing to a live target. The only exception is the FIRST install of a file (no baseline yet), which is inherently safe since nothing existed to overwrite, and even then it's content-compare guarded (won't overwrite an unrelated file that happens to already exist at the target path unless it's byte-identical to the source).

Package **authenticity** (was this `.s7pkg` actually produced by who it claims) is NOT currently cryptographically verified — only content integrity (checksum-based corruption detection) is. This is a known, documented gap (§14, `43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md`).

## 11. Failure Scenarios

See individual feature documents (`12_PACKAGE_UNINSTALLATION.md`, `19_UPDATE_AND_CONFLICT_HANDLING.md`, `24_LICENSING_AND_COMMERCE.md`) for the authoritative per-mechanism failure tables.

## 12. Developer Change Guide

Before adding any new file-writing code path anywhere in this plugin: check whether `PackageUpdatePlanner`/`InstalledFileBaselineService` already covers the case — the single most repeated instruction across this documentation set is to route new file-writing through the existing three-way system rather than writing a parallel one.

## 13. Related Features

`19_UPDATE_AND_CONFLICT_HANDLING.md`, `12_PACKAGE_UNINSTALLATION.md`, `24_LICENSING_AND_COMMERCE.md`, `41_AI_DEVELOPER_GUIDE.md`.

## 14. Known Limitations

Package signing/authenticity verification is architecturally prepared but not implemented (`NullPackageSigner`) — see `24_LICENSING_AND_COMMERCE.md` §10 and `43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md`.
