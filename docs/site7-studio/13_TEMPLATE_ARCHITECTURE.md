# 13 — Template Architecture

**This is the single most important document in this documentation set. Read it fully before touching anything Twig-related.**

## 1. Purpose

State, unambiguously and verified directly against current code, what the real production template rendering path is, and correct any stale assumption that a second rendering system (`templates/site7-components/`) is active.

## 2. What It Does

SITE7 Studio never renders templates itself. It manages the *lifecycle* of one specific kind of file — the real, live-rendering Twig file a Section package owns — through install, sync, versioning, update, and rollback, while the actual rendering continues to be done entirely by the host site's own, unmodified template system.

## 3. Current Status

**Implemented.** The rendering path itself (§4) is the host site's pre-existing architecture, unmodified by SITE7 Studio. SITE7 Studio's install/update/rollback participation in this path is fully implemented and live-verified.

## 4. Architecture — the real rendering path

```
Craft content (a Matrix field value on an Entry)
   ↓
templates/_includes/matrix-container.twig
   ↓  {% include "_blocks/" ~ itemField.type.handle %}   (filename = the Matrix block's Entry Type handle)
   ↓
templates/_blocks/{handle}.twig
```

This is the RP Craft host site's own, pre-existing template architecture (documented independently in the repo-root `docs/21_TEMPLATE_ARCHITECTURE.md`/`docs/22_TEMPLATES_GUIDE.md`, which this documentation set does not depend on but whose *code-level facts* were re-verified directly for this document). SITE7 Studio does not modify this dispatch mechanism in any way.

**`templates/site7-components/` is CONFIRMED DEAD.** Direct verification: `CraftResourceService::generateResources()` and `CraftResourceService::removePackageResources()` — the only two methods in this entire plugin that touch an installed template file — both explicitly target `@templates/_blocks/`, with an in-code comment repudiating the old sandbox by name ("not the old site7-components sandbox... unlike that sandbox, `_blocks/` can already contain a real, hand-maintained file for this handle"). No current code path reads from or writes to `templates/site7-components/`. If you find an older document (including this plugin's own `docs/*.md`, or the repo-root `docs/21_TEMPLATE_ARCHITECTURE.md`) describing it as active, that document is stale — trust this one, verified against the code directly.

> **AI DEVELOPMENT NOTE**: do not resurrect `templates/site7-components/` and do not create any second Twig rendering path. `templates/_blocks/{handle}.twig` is the only production template path, permanently.

## 5. Execution Flow — the complete file lifecycle

```
PACKAGE SOURCE                    packages/{handle}/template.twig
   ↓  (real file, mirrored from    (Site7-owned, safe to overwrite wholesale — see 06_PACKAGE_ARCHITECTURE.md)
   ↓   the live _blocks/ file at
   ↓   import/sync time — never
   ↓   a stub unless no real file
   ↓   existed to copy)
   ↓
INSTALL (11_PACKAGE_INSTALLATION.md)
   ↓                              CraftResourceService::generateResources()
   ↓                              copies template.twig → templates/_blocks/{blockHandle}.twig
   ↓                              ONLY IF the target is missing OR already byte-identical to the
   ↓                              source — the content-compare guard, never overwrites a file it
   ↓                              doesn't recognize, even on a naive re-run
   ↓
BASELINE (16_INSTALLED_FILE_BASELINE.md)
   ↓                              InstalledFileBaselineService::record() — checksum of the file
   ↓                              exactly as it was just written, keyed (packageId, targetPath)
   ↓
[developer may edit templates/_blocks/{handle}.twig directly, on the live site — this is
 completely expected and fully supported by the rest of this lifecycle]
   ↓
SYNC (18_SYNC_FROM_SOURCE.md)     Only changes the PACKAGE'S OWN COPY (re-reads the live file
   ↓                              INTO the package) — never writes back to the live site during
   ↓                              sync. Creates a new VERSION only if the live file differs from
   ↓                              the package's stored copy.
   ↓
VERSION (17_PACKAGE_VERSIONING.md)  Real archive + checksum
   ↓
UPDATE (19_UPDATE_AND_CONFLICT_HANDLING.md)  PackageManagerService::updateInstalledFiles() — THIS
   ↓                              is when the live _blocks/ file can actually change again, via
   ↓                              the three-way baseline/current/incoming comparison. A locally-
   ↓                              modified file is NEVER overwritten; a conflict is reported.
   ↓
ROLLBACK (20_ROLLBACK.md)         Same three-way comparison, target = an OLDER version's archived
   ↓                              content instead of a newer one. Same protection.
```

## 6. Important Classes

**`CraftResourceService`**
`src/services/CraftResourceService.php`
Responsibility: the ONLY writer/deleter of `templates/_blocks/*.twig`.
Important methods: `generateResources(string $packagePath): array` (install path, returns `installedTemplate` info only when the copy actually happened), `removePackageResources(string $packagePath): array` (delete path, content-compare guarded).

**`MatrixEntryTypeImportService`**
`src/services/import/MatrixEntryTypeImportService.php`
Important methods: `copyTemplateTwigFromLiveSource(string $packagePath, string $sourceHandle): bool` — read-only against the live `_blocks/` file, used identically by both fresh import and Sync From Source.

**`SectionUpdateService`**
`src/services/import/SectionUpdateService.php`
Important methods: `diffTwig()` — compares live `_blocks/` content against the package's stored `template.twig` via `PackageArchiveHelper::computeFileChecksum()`.

**`PackageUpdatePlanner`**
`src/services/synchronization/PackageUpdatePlanner.php`
Responsibility: the three-way decision for whether an installed template can be safely updated/rolled back — see `19_UPDATE_AND_CONFLICT_HANDLING.md`.

## 7. Data Model

`site7_installed_files` — one row per installed template, keyed `(packageId, targetPath)`, `resourceHandle` = the block handle, `checksum` = the file's content as installed. See `16_INSTALLED_FILE_BASELINE.md`.

## 8. Filesystem Impact

**Created**: `templates/_blocks/{handle}.twig`, only on install/safe-update, only if missing or content-identical.
**Modified**: same path, on a verified safe update/rollback.
**Deleted**: same path, only on `deletePackage()`, only if still content-identical to the package's own copy.
**Never touched**: `templates/site7-components/` (dead, §4); `templates/_includes/matrix-container.twig` or any other host-site template outside `_blocks/`.

## 9. Events

None dispatched directly by the template-copy operations themselves.

## 10. Validation and Safety

1. **Import**: how an existing RP Craft component is imported — `MatrixEntryTypeImportService::importFromEntryType()` reads `templates/_blocks/{entryType.handle}.twig` if it exists (read-only, `is_file()`+`copy()`) and copies it VERBATIM into the package. A generic stub is written only if no real file exists at import time.
2. **How the real Twig file becomes part of a SITE7 package**: it's copied into `packages/{handle}/template.twig`, the package's own managed copy.
3. **How installation copies it into `templates/_blocks/`**: §5/§6 above — content-compare guarded, never blind.
4. **How the live site renders it**: unchanged, standard Craft/Twig include dispatch (§4) — SITE7 Studio has no runtime involvement in rendering at all.
5. **How uninstall handles it**: `removePackage()` (soft) never touches it; `deletePackage()` removes it only if unmodified (`12_PACKAGE_UNINSTALLATION.md`).
6. **How local modifications are detected**: exclusively by comparing the live file's checksum against the RECORDED BASELINE (`site7_installed_files.checksum`) — never by comparing against the package's CURRENT state, which cannot distinguish "the package changed" from "the developer edited it" (`16_INSTALLED_FILE_BASELINE.md` explains why this distinction matters).
7. **How package updates handle it**: `19_UPDATE_AND_CONFLICT_HANDLING.md`'s three-way system, in full.
8. **How rollback handles it**: `20_ROLLBACK.md` — the identical three-way system, target = an older archived version.
9. **Baseline checksums**: `PackageArchiveHelper::computeFileChecksum()` — sha256 of the file, the same convention used everywhere in this plugin.
10. **If the file was deleted locally**: `PackageUpdatePlanner::classify()` returns `RESULT_LOCAL_DELETION` — **never silently recreated**, treated as deliberate developer intent.
11. **If the developer modified it**: `RESULT_LOCAL_MODIFICATION` — never overwritten by a forward update; the package's own newer content is simply not applied to that file.
12. **If both package and local site changed**: `RESULT_CONFLICT` — never auto-resolved either direction; reported for manual resolution.

## 11. Failure Scenarios

See the full decision table in `19_UPDATE_AND_CONFLICT_HANDLING.md` — every case above maps directly to one row of that table.

## 12. Developer Change Guide

If changing template installation:
```
CraftResourceService::generateResources()
   ↓
InstalledFileBaselineService (baseline recording)
```
If changing template update/conflict behavior:
```
PackageUpdatePlanner (decision)
   ↓
PackageManagerService::applySafeFileUpdate() (execution)
```
**Never** write directly to `templates/_blocks/*.twig` from a new code path without going through the three-way check — this is the single rule most likely to silently destroy developer work if violated.

## 13. Related Features

`06_PACKAGE_ARCHITECTURE.md`, `11_PACKAGE_INSTALLATION.md`, `14_IMPORT_EXISTING_SECTION.md`, `16_INSTALLED_FILE_BASELINE.md`, `18_SYNC_FROM_SOURCE.md`, `19_UPDATE_AND_CONFLICT_HANDLING.md`, `20_ROLLBACK.md`.

## 14. Known Limitations

- The install-time mapping assumes a Section package's `matrix.yaml` has exactly one block, and maps to exactly one `_blocks/` target file (an explicit "MVP: assume first block handle" simplification in `CraftResourceService`).
- `templates/site7-components/` still contains one leftover file (`clientLogos.twig`) predating this architecture — harmless, never read/written by any current code path, not cleaned up (documentation-only task scope — see `43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md`).
