# Phase 1 — Starter Kit Manifest Schema Foundations

Status: **Implemented.** Schema-only, per `WEBSITE-STARTER-KIT-SYSTEM.md` Phase 1 — no capture or install logic reads/writes the new fields yet; later phases (2-8) build against this surface.

## What changed

`src/models/packages/PackageManifest.php`:
- `dependencies` (existing array) documented to also carry, under new keys the model doesn't validate individually (same "safe array" pattern as every other manifest array):
  - `plugins[]` — `{handle, package, versionConstraint}`, the future *installable* plugin dependency list. Distinct from the existing `dependencies.pluginDependencies[]`, which is reporting-only (flags a missing plugin against a captured field; never installed).
  - `npmPackages[]` — `{name, version, dev}`.
  - `frontendTooling` — `{system, configFiles[]}` (build system identifier + its config file paths).
- Five new top-level array properties, all defaulting to `[]`: `assetVolumes`, `categoryGroups`, `tagGroups`, `navigation`, `projectConfigPaths`. Added to `defineRules()`'s `safe` list alongside the other array fields — no nested schema validation exists anywhere in this model today, so these follow the established convention rather than introducing one.
- A manifest written before this phase parses and validates exactly as before; every new field simply reads back as `[]`.

`src/services/StarterKitInstallationService.php` — fixed the documented "globals drop bug": `installStarterKit()` never read `manifest->globals` at all, so Global Set values captured by `WebsiteImportService` were silently discarded on install. Added `installGlobals()`:
- Looks up each captured Global Set by handle (`Craft::$app->getGlobals()->getSetByHandle()`); a handle missing on the target site is appended to `$skipped` (not a hard failure — same best-effort pattern already used for missing Templates/Entry Types).
- Restores field values via `setFieldValue()` only for fields still present on the target's field layout (mirrors the existing `entryFields` restore in `TemplateInsertionService::createEntryFromTemplate()`); unknown fields are silently ignored, not errored.
- Saves via `Craft::$app->getElements()->saveElement()`; a validation failure is appended to `$skipped` with the element's first errors.
- Return shape gained `installedGlobals: string[]` (handles actually restored). `StarterKitGeneratorController::actionInstall()` now passes this through in the JSON response alongside the existing `installedTemplates`/`skipped`.

## Tests

`tests/unit/models/packages/PackageManifestTest.php` (new, Codeception `Unit` convention matching `ManifestReaderTest.php`):
- `testLoadsLegacyManifestWithoutNewFields` — a pre-Phase-1 manifest still validates, new properties read back as `[]`.
- `testRoundTripsNewSchemaFields` — every new field (including the nested `dependencies.plugins`/`npmPackages`/`frontendTooling` keys) survives a `json_encode`/`json_decode`/`new PackageManifest()` round trip.

## Live verification

Per this project's established practice, `php -l`/unit tests alone don't exercise real Craft API behavior, so the globals fix was additionally verified live against the DDEV site: a temporary Global Set + Plain Text field were created, `StarterKitInstallationService::installGlobals()` was invoked directly (via reflection, since it's private) with a captured-globals payload containing a real handle+field, an unknown field on that same entry, and a nonexistent Global Set handle. Result: the real field value was restored and persisted (confirmed by re-fetching), the unknown field was silently ignored, and the nonexistent handle was correctly skipped and reported. Temporary fixtures were deleted afterward, leaving no lasting change on the dev site.

## Deferred to later phases

Nested validation of the new array shapes (e.g. rejecting a `plugins[]` entry missing `handle`), capture logic for any of these fields (Phase 2 for volumes/category/tag groups, Phase 3 for navigation, Phase 4 for frontend tooling/npm/plugins), and any install-side consumption beyond the `globals` fix above (Phase 6+).
