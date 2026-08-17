# 12 — Package Uninstallation

## 1. Purpose

Clearly separate four operations that are easy to confuse: **uninstall** ("remove"), **delete**, **detach**, and **disable**. Each has a completely different effect on package source, installed files, Craft resources, and history.

## 2. What It Does

`PackageManagerService` exposes four distinct methods, each documented separately below.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
                     status column          packages/{handle}/    installed files    Craft resources
removePackage()   →  'available'             untouched              untouched         unlinked from Matrix only
disablePackage()  →  'disabled'              untouched              untouched         unlinked from Matrix only
deletePackage()   →  row DELETED (cascade)   directory DELETED       content-guard      removed if unused
detachPackage()   →  row DELETED (cascade)   directory DELETED       (cascade)          NEVER touched
```

## 5. Execution Flow

**`removePackage($handle)`** ("uninstall"/soft): if `type === 'section'`, calls `unlinkFromMatrix($handle)` only — **deliberately never calls `CraftResourceService::removePackageResources()`**, so a later `installPackage()` reuses the exact same Entry Type/Fields rather than orphaning any content already authored against them. `status → 'available'`.

**`disablePackage($handle)`**: same Matrix-unlink, `status → 'disabled'`.

**`deletePackage($handle)`** (permanent, Dev-Mode-gated with a self-captured-Template exception, §`08_PACKAGE_AUTHORING.md`): unlinks from Matrix, calls `CraftResourceService::removePackageResources($packagePath)` (usage-checked — see §10), deletes the `PackageRecord` row (cascading every dependent table via FK, §`05_DATABASE_ARCHITECTURE.md`), then deletes the `packages/{handle}/` directory from disk.

**`detachPackage($handle)`** (Dev-Mode-only, no exception — the strictest gate in the plugin): "undo an import by mistake" — deletes the `PackageRecord` (same cascade) and the source directory, but **never calls `removePackageResources()`** — the live Entry Type/Fields the package was imported from (or generated) are left completely untouched, as if the import had never happened.

## 6. Important Classes

**`PackageManagerService`**
`src/services/PackageManagerService.php`
Important methods: `removePackage()`, `disablePackage()`, `deletePackage()`, `detachPackage()`.
Called by: `PackageActionController::actionRemove/Disable/Delete/Detach()`.

**`CraftResourceService::removePackageResources()`**
`src/services/CraftResourceService.php`
Responsibility: usage-checked deletion of a Section package's Craft Fields/Entry Type/installed template.

**`PackageUsageService`**
`src/services/PackageUsageService.php`
Responsibility: pre-flight usage check surfaced in the CP before `remove`/`delete` are even attempted.

## 7. Data Model

`deletePackage()`/`detachPackage()` rely entirely on `ON DELETE CASCADE` FKs (§`05_DATABASE_ARCHITECTURE.md`) to clean up `site7_components`/`site7_templates`/`site7_package_dependencies`/`site7_package_versions`/`site7_package_publications`/`site7_*_import_sources`/`site7_installed_files` — no explicit per-table delete code exists for any of these.

## 8. Filesystem Impact

| Operation | `packages/{handle}/` | `templates/_blocks/{handle}.twig` | Owned-file targets |
|---|---|---|---|
| `removePackage` | untouched | untouched | untouched |
| `disablePackage` | untouched | untouched | untouched |
| `deletePackage` | deleted | deleted **only if still byte-matches** the package's own `template.twig` | same content-compare guard |
| `detachPackage` | deleted | **never touched** | **never touched** |

**Archives are NOT deleted** by any of these four operations — `.s7pkg` files in `storage/site7-studio/exports/`/`marketplace-repo/` are only removed by their own retention logic (`26_BACKUP_AND_RECOVERY.md`), never as a side effect of package deletion. This is a known, accepted storage-growth characteristic (see §14).

## 9. Events

None dispatched directly by these four methods.

## 10. Validation and Safety

**Usage-aware deletion**: `removePackageResources()` (called only by `deletePackage()`) checks `Entry::find()->typeId($entryType->id)->status(null)->count()` — if any real Entry elements still use the Entry Type, it's skipped (not deleted), reported in the return warnings. Same for Fields via `Craft::$app->getFields()->findFieldUsages($field)`. The installed template is only removed if it still byte-matches the package's own `template.twig` — a locally-modified installed file is left in place and reported, never destroyed.

**`detachPackage()`'s strict gate**: no self-captured-Template exception (unlike `deletePackage()`) — this is intentionally the most locked-down operation in the plugin, since it removes package tracking without any of `deletePackage()`'s usage safety checks (because it never touches Craft resources at all, those checks are unnecessary for it).

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Entry Type still has real content | `removePackageResources()` skips deleting it, adds a warning, `deletePackage()` still completes for everything else |
| Field still used by another field layout | Same skip-and-warn behavior |
| Installed template locally modified | Left in place, reported as skipped |
| `removePackage()`/`disablePackage()` on a nonexistent package | No explicit guard found beyond the standard `getPackageByHandle()` null check upstream in the controller |

## 12. Developer Change Guide

If you need "remove but keep resources reusable": use `removePackage()`.
If you need "permanently delete, safely, respecting usage": use `deletePackage()`.
If you need "undo an accidental import without touching anything it linked to": use `detachPackage()`.
**Never** add a fifth uninstall variant without first confirming one of these four doesn't already do what you need — this is one of the most carefully differentiated parts of the plugin.

## 13. Related Features

`11_PACKAGE_INSTALLATION.md`, `13_TEMPLATE_ARCHITECTURE.md`, `16_INSTALLED_FILE_BASELINE.md`, `26_BACKUP_AND_RECOVERY.md`.

## 14. Known Limitations

`deletePackage()`/`detachPackage()` never delete the underlying `.s7pkg` archive files from disk — only DB rows referencing them. This is a documented, accepted characteristic (avoids the risk of deleting an archive someone still needs, e.g. a `marketplace-repo/` backup for a still-existing package elsewhere), not scheduled for a change.
