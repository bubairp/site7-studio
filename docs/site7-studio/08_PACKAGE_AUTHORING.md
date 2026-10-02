# 08 — Package Authoring

## 1. Purpose

Explain how packages are manually created and edited, and the locked-field rules that protect imported-package provenance.

## 2. What It Does

Backs the New Package wizard and the Package Editor CP screens.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
User (CP)
   ↓
PackageAuthoringController
   ↓
PackageAuthoringService
   ↓
packages/{handle}/manifest.json  +  site7_packages row
```

## 5. Execution Flow

**Create**: `PackageAuthoringService::createPackage(array $meta)` — validates `type` (must be one of `VALID_TYPES`), validates `name` non-empty, derives/validates `handle` (kebab-case regex `^[a-z0-9]+(-[a-z0-9]+)*$`), checks the handle isn't already taken, scaffolds `manifest.json` + `README.md` + `preview/`, calls `PackageManagerService::discoverPackages()`, sets `authoringStatus = 'draft'`, then **immediately calls `PackageBackupService::backupToLocalRepository($handle)`** — even a brand-new, empty-shell package is instantly recoverable.

**Edit**: `PackageAuthoringService::updatePackage(string $handle, array $fields)` — the single write path for manifest metadata (name/description/category/tags/author/version/publishing fields). Locked-field check runs first (see §10).

## 6. Important Classes

**`PackageAuthoringService`**
`src/services/PackageAuthoringService.php`
Responsibility: package create/edit, locked-field enforcement, type-specific composition editing.
Important methods: `createPackage()`, `updatePackage()`, `saveSectionFields()`, `savePatternComposition()`, `saveTemplateComposition()`, `saveStarterKitComposition()`, `savePreviewImage()`.
Called by: `PackageAuthoringController` (every action), `VersionManagerService::createVersion()` (for the `version` field write, §17).
Dependencies: `PackageManagerService`, `PackageBackupService`, `SectionImportSourceRepository`/`PageImportSourceRepository`/`WebsiteImportSourceRepository` (locked-field checks).

## 7. Data Model

`site7_packages` — see `05_DATABASE_ARCHITECTURE.md`.

## 8. Filesystem Impact

**Created**: `packages/{handle}/manifest.json`, `README.md`, `preview/` on `createPackage()`.
**Modified**: `manifest.json` on every `updatePackage()`/composition-save call.
**Never touched**: any *installed* file (`templates/_blocks/`, owned-file targets) — authoring only ever touches the package's own source.

## 9. Events

None dispatched directly by this service.

## 10. Validation and Safety

**Locked-field rule**: if `isLockedImportedSection()`/`isLockedImportedPage()`/`isLockedImportedWebsite()` returns true (a row exists in the corresponding `site7_*_import_sources` table), `updatePackage()` silently drops every submitted field except `description`/`category`/`tags`/**`version`** — `name`/`author` are protected because they come from the live Craft source. `version` is deliberately allowed through this lock (fixed during the version-integrity work) because a version bump is always system-computed (`VersionManagerService::createVersion()`), never a human hand-typing a string — the Package Editor's version field stays disabled in the UI regardless of this backend allowance.

**Handle uniqueness**: checked at create time; a duplicate handle throws.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Handle already taken | `createPackage()` throws `Exception("A package with the handle '...' already exists.")` |
| Invalid handle format | Throws before any file is written |
| `updatePackage()` on a nonexistent package | Throws `Exception('Package not found.')` |
| `updatePackage()` on a locked imported package with `name`/`author` submitted | Silently dropped, no error — only the allowed subset is written |

## 12. Developer Change Guide

If changing what's editable on an imported package: modify the `array_intersect_key()` allow-list inside `updatePackage()`'s locked-field branch — do not add a second lock-check path elsewhere.

If adding a new package origin (a fourth "locked" source type): add a new `isLockedImportedX()` private method following the existing three, and a corresponding `site7_*_import_sources` table (mirror `SectionImportSourceRepository`'s exact shape).

## 13. Related Features

`06_PACKAGE_ARCHITECTURE.md`, `10_PACKAGE_IMPORT.md`, `14_IMPORT_EXISTING_SECTION.md`, `17_PACKAGE_VERSIONING.md`, `26_BACKUP_AND_RECOVERY.md`.

## 14. Known Limitations

None confirmed beyond what's stated in §10 (the lock's exact allow-list is deliberately narrow and static — adding a new editable field for locked packages requires a code change, not a config change).
