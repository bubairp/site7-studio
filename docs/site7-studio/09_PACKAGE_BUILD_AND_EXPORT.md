# 09 — Package Build and Export

## 1. Purpose

Explain exactly how a package becomes a `.s7pkg` archive, what that archive contains, and how its checksum is computed — the foundation every version, backup, publish, and rollback operation in this plugin relies on.

## 2. What It Does

`PackageExportService::exportPackage()` zips a package (and, optionally, its full dependency closure) into a `.s7pkg` file, computes a deterministic content checksum, and records a version-history row. `PackageBuilderService` adds a thin publishing-specific layer on top (npm-style `package.json`, `CHANGELOG.md`, `LICENSE.md`) before delegating to the same export call.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
Package (packages/{handle}/)
   ↓
PackageExportService::exportPackage($handle, $includeDependencies)
   ↓  resolveDependencyClosure() — walks `requires` graph (+ Starter Kit page→template refs)
   ↓  PackageArchiveHelper::computeDirectoryChecksum() — per bundled package
   ↓  ZipArchive: bundle-manifest.json + packages/{handle}/... (full recursive directory copy)
   ↓
MarketplaceService::recordVersion($record, $checksum, $archivePath)  — dedup-safe on (packageId, version)
   ↓
site7_package_versions row: {version, checksum, archivePath}
```

## 5. Execution Flow

1. `resolveDependencyClosure($rootHandle)` — BFS over `requires` (root handle first), type-specific: Pattern→`requires.sections`, Template→`requires.patterns`+`requires.sections`, Starter Kit→`requires.templates`+every `pages[].templateHandle`. Throws if a required handle can't be resolved.
2. For each package in the closure: locate its directory, compute `PackageArchiveHelper::computeDirectoryChecksum($path)`.
3. Build a `PackageBundleManifest` model (`schemaVersion`, `generatedAt`, `rootHandle`, `rootType`, `craftVersion`, `site7Version`, `packages: [{handle,type,version,checksum}]`, `requiredSharedResources`).
4. Open a `ZipArchive`, write `bundle-manifest.json` as the root entry, then `PackageArchiveHelper::addDirectoryToZip()` for every package in the closure — a generic, recursive, type-unaware copy (this is why owned frontend files, §21, required zero export-side code changes when added).
5. `recordExportedVersions()` calls `MarketplaceService::recordVersion()` for every package in the closure (root + dependencies if included).
6. Dispatches `PackageExportedEvent`.

**Publish-only addition** (`PackageBuilderService::build()`): writes `package.json` (npm-style descriptor mirroring the manifest), `CHANGELOG.md` (built from `PackageVersionRecord` history), `LICENSE.md` (from `manifest->license`, only if set) into the package directory **before** delegating to `exportPackage()` — so these files end up inside the archive too.

## 6. Important Classes

**`PackageExportService`**
`src/services/PackageExportService.php`
Responsibility: build `.s7pkg` archives, resolve dependency closures, record versions.
Important methods: `exportPackage(string $handle, bool $includeDependencies = true): string`, `resolveDependencyClosure(string $rootHandle): array`.
Called by: `VersionManagerService::createVersion()` (with `includeDependencies: false`), `PackageBackupService`, `PackagePublisherService`, `MarketplaceController::actionExport()`.

**`PackageArchiveHelper`**
`src/services/support/PackageArchiveHelper.php`
Responsibility: stateless zip/checksum primitives — the ONE implementation of each.
Important methods: `computeDirectoryChecksum(string $path): string`, `computeFileChecksum(string $path): ?string`, `addDirectoryToZip()`, `extractZip()`, `readEntry()`, `replaceDirectory()`.
Called by: nearly every service in this documentation set that touches archives or checksums.

**`PackageBuilderService`**
`src/services/publishing/PackageBuilderService.php`
Responsibility: publish-specific package augmentation, delegating archive work entirely to `PackageExportService`.

## 7. Data Model

`site7_package_versions` — see `17_PACKAGE_VERSIONING.md` for full detail on this table's write path.

## 8. Filesystem Impact

**Created**: a new `.s7pkg` under `storage/site7-studio/exports/{handle}-{version}-{timestamp}.s7pkg`.
**Modified**: nothing outside the new archive (for publish, `package.json`/`CHANGELOG.md`/`LICENSE.md` inside the package source, before archiving).
**Never touched**: any existing, previously-created `.s7pkg` file — archives are write-once (§10).

## 9. Events

`PackageExportedEvent` — dispatched at the end of `exportPackage()`.
`PackageBuiltEvent` — dispatched at the end of `PackageBuilderService::build()`.

## 10. Validation and Safety

**Checksum algorithm** (`computeDirectoryChecksum()`): for every file under the directory (excluding `.DS_Store`, `Thumbs.db`, `.gitignore`, `.gitkeep`, `*.swp`/`*.tmp`/`*.bak`), compute sha256; sort by relative path; hash the joined `"path:hash\n"` lines. Deterministic regardless of mtime/permissions/OS.

**Archive immutability**: no code path in this plugin ever rewrites an existing `.s7pkg` file after it's written. This is the structural foundation of version immutability (`17_PACKAGE_VERSIONING.md`).

**Dependency closure inclusion**: `exportPackage($handle, true)` (default, used by publish/backup) bundles the full closure for a self-contained archive. `createVersion()` deliberately calls it with `false` — a version row represents this package's own state only.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Required dependency handle can't be resolved | `resolveDependencyClosure()` throws, export aborts before any zip is written |
| Package directory missing on disk | `exportPackage()` throws before opening the zip |
| Zip file can't be created (disk/permissions) | `ZipArchive::open()` failure throws `Exception("Could not create archive at ...")` |

## 12. Developer Change Guide

If changing archive content: modify `PackageArchiveHelper::addDirectoryToZip()`'s exclusion list only if you need to exclude a new category of cruft file — never add per-file-type special-casing here (the whole point of this helper is that it's generic).

If changing what gets written before archiving (like `PackageBuilderService`'s three extra files): add to `PackageBuilderService::build()`, not `PackageExportService::exportPackage()` — keep the core archive mechanism free of publish-specific concerns.

## 13. Related Features

`17_PACKAGE_VERSIONING.md`, `10_PACKAGE_IMPORT.md`, `20_ROLLBACK.md`, `26_BACKUP_AND_RECOVERY.md`, `30_PACKAGE_PUBLISHING.md` (folded into `23_MARKETPLACE_ARCHITECTURE.md`).

## 14. Known Limitations

None confirmed — this is one of the most thoroughly exercised and tested parts of the codebase (every version/backup/publish/rollback operation depends on it working correctly).
