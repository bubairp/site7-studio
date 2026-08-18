# 10 — Package Import

## 1. Purpose

Explain how a `.s7pkg` archive (produced by export/publish) is imported onto a site — distinct from "Import Existing Section/Page/Website," which import *from a live Craft site's own content* (see `14_IMPORT_EXISTING_SECTION.md`, `15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md`).

## 2. What It Does

`PackageImportService` validates an archive, then optionally writes its contents onto disk and installs the result.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
.s7pkg file
   ↓
validatePackage($path)
   extracts to storage/runtime/site7-studio/import/{uuid}, reads bundle-manifest.json,
   validates it, checksums each bundled package, classifies each:
     newPackages | alreadyInstalled (checksum match) | conflicts (handle exists, checksum differs)
   ↓ (nothing written to @packages or the DB yet)
importPackage($validationResult, $options)
   ↓ for each bundled package not already-installed/skipped-conflict:
   ↓    PackageArchiveHelper::replaceDirectory($extractedSource, packages/{handle})  — wholesale replace
   ↓
PackageManagerService::discoverPackages()
   ↓
MarketplaceService::recordVersion() + syncDependencyRecords()  — for every bundled package
   ↓ (if $options['install'])
PackageManagerService::installPackage($rootHandle) [+ enablePackage()]
   ↓
PackageImportedEvent dispatched
```

## 5. Execution Flow

1. `validatePackage(string $s7pkgPath): PackageValidationResult` — extracts the zip to a scratch temp dir, reads/validates `bundle-manifest.json` (schema version mismatch → warning, not error), checks Craft-version compatibility (major-version mismatch → warning), warns if a `requiredSharedResources` entry isn't registered on this site. For each bundled package: recomputes its checksum and classifies it against the local `PackageManagerService::getPackageByHandle()` state.
2. `importPackage(PackageValidationResult $validation, array $options = [])` — for each bundled package, skips `alreadyInstalled` and (unless `overwriteConflicts`) `conflicts`; otherwise `PackageArchiveHelper::replaceDirectory()` — wholesale delete-then-copy of `packages/{handle}/`.
3. `PackageManagerService::discoverPackages()` re-scans and registers the newly-written packages.
4. For every bundled package: `MarketplaceService::recordVersion()` (dedup-safe) + `syncDependencyRecords()`.
5. If `$options['install']` (default true): `installPackage($rootHandle)`, then `enablePackage()` if `$options['enable']`.
6. Temp extraction directory is cleaned up.
7. `PackageImportedEvent` dispatched with a summary (`{installed, skipped, errors}`).

## 6. Important Classes

**`PackageImportService`**
`src/services/PackageImportService.php`
Responsibility: validate + write + (optionally) install a `.s7pkg`.
Important methods: `validatePackage(string $s7pkgPath): PackageValidationResult`, `importPackage(PackageValidationResult $validation, array $options = []): array`.
Called by: `MarketplaceController::actionImportUpload()`/`actionImportInstall()`, `MarketplaceService::updatePackage()`/`installFromRepository()`.
Dependencies: `PackageArchiveHelper`, `PackageManagerService`, `MarketplaceService`.

## 7. Data Model

`site7_packages`, `site7_package_versions`, `site7_package_dependencies` — all touched via the usual owning services (`PackageManagerService`, `MarketplaceService`), not written directly by this service.

## 8. Filesystem Impact

**Created/Modified**: `packages/{handle}/` — wholesale directory replace, for each bundled package not skipped.
**Deleted**: the temp extraction directory, cleaned up after import.
**Never touched**: `templates/_blocks/{handle}.twig` or any other *installed* file directly — this service only writes to the package's own SOURCE directory. The installed side only changes if `$options['install']` triggers `installPackage()`, which then follows its own guarded copy logic (`11_PACKAGE_INSTALLATION.md`).

## 9. Events

`PackageImportedEvent` — dispatched at the end of `importPackage()`, carrying `{rootHandle, summary}`.

## 10. Validation and Safety

- **Archive checksum verification**: `validatePackage()` recomputes each bundled package's checksum from the extracted files and compares against `bundle-manifest.json`'s recorded value — mismatch is a hard error ("archive may be corrupted or altered"), before anything is written to `@packages`.
- **Conflict detection**: a handle that already exists locally with *different* content is classified `conflicts` and, without `overwriteConflicts: true`, is left completely untouched.
- **`overwriteConflicts` option**: `MarketplaceService::updatePackage()` (Marketplace "Update" button) and `MarketplaceService::installFromRepository()` (Repository tab "Install" button) both always pass `true` — an unconditional overwrite is expected and intentional for both automatic paths. The manual Import flow additionally exposes this as a user-facing opt-in checkbox (`MarketplaceController::actionImportInstall()` reads `overwriteConflicts` from the request body, default `false`; `src/templates/marketplace/_import.twig`), so a human-initiated wholesale overwrite is also possible outside the two automatic Marketplace call sites.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| File isn't a valid zip / missing `bundle-manifest.json` | `validatePackage()` returns a result with `valid: false` and an error message; nothing written |
| Checksum mismatch on a bundled package | Error added for that package; whole import can still proceed for other, valid bundled packages, but the corrupted one is skipped |
| Handle exists with identical content | Classified `alreadyInstalled`, silently skipped (not an error) |
| Handle exists with different content, no `overwriteConflicts` | Classified `conflicts`, skipped, reported |
| `install: true` but `installPackage()` fails | Failure caught, added to the summary's `errors`, does not abort already-written package directories |

## 12. Developer Change Guide

If changing conflict detection: modify `validatePackage()`'s classification logic (checksum comparison against `getPackageByHandle()`) — do not add a second validation path in `importPackage()` itself, which trusts the already-computed classification.

If changing the directory-replace mechanism: it's shared with `PackageRollbackService` via `PackageArchiveHelper::replaceDirectory()` — a change here affects rollback too (`20_ROLLBACK.md`).

## 13. Related Features

`09_PACKAGE_BUILD_AND_EXPORT.md`, `23_MARKETPLACE_ARCHITECTURE.md`, `20_ROLLBACK.md` (shares `replaceDirectory()`).

## 14. Known Limitations

None confirmed beyond the general note that this path operates on package SOURCE only — it never touches installed files directly (see §8), which is by design, not a gap.
