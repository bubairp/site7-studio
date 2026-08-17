# 06 — Package Architecture

## 1. Purpose

Define what a SITE7 package physically is, the difference between package *source* and *installed* resources, and the complete lifecycle every other document in this set details one stage of.

## 2. What It Does

A package is a directory (`packages/{handle}/`) containing a `manifest.json` plus type-specific content. It is Site7 Studio's own managed, portable unit of reusable Craft content.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
packages/{handle}/
├── manifest.json          # REQUIRED - single source of truth for metadata (07_PACKAGE_MANIFEST.md)
├── README.md               # Human-readable description (auto-generated for imports)
├── fields.yaml             # Section packages: captured field definitions
├── matrix.yaml             # Section packages: Entry Type/block definition
├── template.twig           # Section packages: REAL rendering Twig, mirrored from templates/_blocks/
├── preview/
│   ├── preview-data.yaml
│   └── preview.{png,jpg,...}
├── demo/                   # (Some package types) demo content
├── resources/               # (Some package types) additional bundled resources
└── frontend/                # ONLY present if the package explicitly owns frontend files (21_FRONTEND_FILE_OWNERSHIP.md)
                             # or captured whole-environment frontend config (Website/Starter Kit packages)
```

**Package source vs. installed resources — the core distinction**: everything under `packages/{handle}/` is Site7-owned authoring storage — safe to overwrite wholesale during import/sync/rollback, because no human hand-edits it directly outside the Package Editor/Import flows. What that source *produces* on the host site — Craft Fields, Entry Types, and `templates/_blocks/{handle}.twig` — is genuinely developer-editable live content, and is what every safety mechanism in this documentation set (baseline, three-way conflict, rollback protection) exists to protect. Confusing these two is the most common category of architectural mistake in this codebase's own history.

**Package types** (`src/models/packages/*.php`): `SectionPackage` (`'section'`), `TemplatePackage` (`'template'`), `PatternPackage` (`'pattern'`), `StarterKitPackage` (`'starter-kit'`), `ThemePackage` (`'theme'`). All actual structure lives in `PackageManifest`, not these ~10-line subclasses.

## 5. Execution Flow — the complete lifecycle

```
Create/Author (08_PACKAGE_AUTHORING.md)   or   Import (10_PACKAGE_IMPORT.md, 14, 15)
   ↓
Manifest written (07_PACKAGE_MANIFEST.md)
   ↓
First real Version — real archive + checksum (17_PACKAGE_VERSIONING.md)
   ↓
Build/Export (09_PACKAGE_BUILD_AND_EXPORT.md)
   ↓
Publish [optional] (23_MARKETPLACE_ARCHITECTURE.md)
   ↓
Install (11_PACKAGE_INSTALLATION.md)
   ↓
Installed-File Baseline recorded (16_INSTALLED_FILE_BASELINE.md)
   ↓
[developer edits installed files directly]
   ↓
Sync From Source (18_SYNC_FROM_SOURCE.md) — one new version if anything changed
   ↓
Update (19_UPDATE_AND_CONFLICT_HANDLING.md) — safe/conflict-aware
   ↓
Rollback [as needed] (20_ROLLBACK.md)
   ↓
Uninstall / Delete / Detach (12_PACKAGE_UNINSTALLATION.md)
```

## 6. Important Classes

**`PackageManagerService`** — `src/services/PackageManagerService.php`. Package registry; install/enable/disable/remove/delete orchestration. See `11_PACKAGE_INSTALLATION.md`/`12_PACKAGE_UNINSTALLATION.md` for full detail.

**`PackageReader`** — `src/services/engine/PackageReader.php`. Reads `manifest.json` off disk into a `PackageManifest` + the correct `Package` subclass.

**`PackageDiscovery`** — `src/services/engine/PackageDiscovery.php`. Scans `packages/` and registers/updates `PackageRecord` rows.

## 7. Data Model

`site7_packages` (registry) + `site7_components`/`site7_templates` (type-specific CP metadata). Full detail: `05_DATABASE_ARCHITECTURE.md`.

## 8. Filesystem Impact

**Created**: `packages/{handle}/` and its contents, at creation/import time.
**Modified**: by sync, authoring edits, import overwrite, rollback restore.
**Deleted**: on permanent delete only (`12_PACKAGE_UNINSTALLATION.md`).
**Never touched by this layer directly**: `templates/_blocks/`, owned-file targets — those are the *installed* side, a different concern (`11_PACKAGE_INSTALLATION.md`).

## 9. Events

`ResourceImportedEvent`, `PackageImportedEvent`, `PackageExportedEvent` — see `27_EVENTS_AND_HOOKS.md`.

## 10. Validation and Safety

See each lifecycle-stage document for its own validation layer — there is no single "package validator" covering the whole lifecycle; validation is deliberately scoped per stage (`31_SECURITY_AND_VALIDATION.md`).

## 11. Failure Scenarios

See the specific stage document (e.g. `10_PACKAGE_IMPORT.md` §11, `19_UPDATE_AND_CONFLICT_HANDLING.md` §11) — failure handling is stage-specific, not centralized.

## 12. Developer Change Guide

If you're not sure which document covers the change you want to make, start here and follow the lifecycle diagram in §5 to the matching stage document.

## 13. Related Features

Every other document in this set is, in some sense, "related" — this document is the index for the package lifecycle specifically.

## 14. Known Limitations

See `43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md` for the consolidated list.
