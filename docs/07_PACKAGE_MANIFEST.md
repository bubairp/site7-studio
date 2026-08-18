# 07 — Package Manifest

## 1. Purpose

Document the exact `manifest.json` schema — every field, its type, who writes it, who reads it, and the backward-compatibility guarantee that lets old manifests keep working as new fields are added.

## 2. What It Does

`manifest.json` is the single source of truth for a package's metadata. `src/models/packages/PackageManifest.php` (`class PackageManifest extends craft\base\Model`) is its in-memory representation.

## 3. Current Status

**Implemented.** Backward compatibility for every schema generation is directly covered by dedicated unit tests (`tests/unit/models/packages/PackageManifestTest.php`).

## 4. Architecture

```
manifest.json (on disk)
   ↓  json_decode()
PackageReader::readPackage()  →  new PackageManifest($decodedArray)
   ↓
$manifest->validate()  (Yii/Craft Model validation — required: type/handle/name/version/schemaVersion only)
   ↓
Package subclass instance (SectionPackage/TemplatePackage/...) holds $package->manifest
```

**Written by**: no single "manifest writer" service — whichever service last legitimately changed the package writes it directly (`json_encode`/`file_put_contents`): `MatrixEntryTypeImportService` (import), `PackageAuthoringService::updatePackage()` (authoring edits), `SectionUpdateService`/`PageUpdateService` (sync), `VersionManagerService::createVersion()` (version bump), `PackageRollbackService` (wholesale restore from archive).

## 5. Execution Flow

Reading: `PackageReader::readPackage($path)` → `json_decode(file_get_contents($path.'/manifest.json'), true)` → `new PackageManifest($data)` → `$manifest->validate()` (throws if `type`/`handle`/`name`/`version`/`schemaVersion` are missing) → `createPackageInstance($manifest->type)` picks the right subclass.

Writing: every writer follows the same pattern — read the current `manifest.json` as an array, merge/overwrite specific keys, `json_encode(..., JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)`, `file_put_contents()`. There is no shared "manifest writer" helper — each service writes exactly the keys it owns.

## 6. Important Classes

**`PackageManifest`**
`src/models/packages/PackageManifest.php`
Responsibility: the manifest schema itself, plus Yii `Model` validation rules.
Important properties: see the full table in §7.
Callers (readers): `PackageReader`, every service listed above.
Callers (writers): see §2.

**`PackageReader`**
`src/services/engine/PackageReader.php`
Responsibility: `manifest.json` → hydrated `PackageManifest` + correct `Package` subclass.

## 7. Data Model — full property reference

**Backward compatibility rule**: every field added since the original schema is a plain public property with a safe default (`[]`, `null`, or a literal), added to the `'safe'` validation rule — never `'required'`. A manifest written before a given field existed simply has it at its default after loading. No manifest migration has ever been required for a schema addition.

| Property | Type | Required | Meaning | Written by |
|---|---|---|---|---|
| `schemaVersion` | string | required | Always `'1'` currently | every writer |
| `type` | string | required | `section`\|`template`\|`pattern`\|`starter-kit`\|`theme` | package creation (never changes) |
| `handle` | string | required | Kebab-case, globally unique | package creation |
| `name` | string | required | Display name | authoring/import |
| `version` | string | required | Semver `MAJOR.MINOR.PATCH` | `VersionManagerService::createVersion()` is the intended sanctioned writer of a NEW value, but this is **UI-enforced only** — `PackageAuthoringService::updatePackage()`'s general field loop accepts a `version` key from any caller, including `PackagePublisherController::actionSaveMetadata()` (whose field allow-list also includes `version`); the backend does not itself block a `version` write from outside `VersionManagerService`. |
| `author`, `description`, `category`, `tags` | string/array | optional | Basic metadata | `PackageAuthoringService::updatePackage()` |
| `compatibility` | array | optional | Declared on the schema (`'safe'`-validated) but currently **dead** — no service in `src/` writes it; `updatePackage()`'s writable-field list does not include it. |
| `dependencies` | array | optional | `{sharedResources, pluginDependencies, plugins, npmPackages, frontendTooling}` — last three Website/Starter-Kit-only | import services |
| `requires` | array | optional | Frozen package→package dependency graph, by handle | authoring/import |
| `ownedFiles` | array | optional (added Step 8.1) | `[{sourcePath, targetPath, type}]` — explicit owned frontend/asset files | `MatrixEntryTypeImportService::captureOwnedFiles()`, sync (content only) |
| `importedFrom` | array | optional | `{sourceType, sourceId, sourceHandle, sourceUid, sourceHash, importedAt, importedBy}` — imported packages only | import services |
| `excludedFields` | array | optional | Detected-but-not-captured fields, for transparency | import services |
| `pages`, `globals` | array | optional | Starter-Kit-only | `WebsiteImportService` |
| `assetVolumes`, `categoryGroups`, `tagGroups`, `craftSections`, `navigation`, `projectConfigPaths` | array | optional | Captured native-resource settings (referenced-only) | `WebsiteImportService`/`ProjectBuilder` |
| `displayName`, `company`, `website`, `supportUrl`, `documentationUrl`, `license`, `pricingType`, `minimumCraftVersion`, `minimumSite7Version`, `keywords` | string/array | optional | Publishing/catalog metadata | `PackagePublisherController::actionSaveMetadata()` (Publish wizard's Metadata step; there is no `PackageAuthoringController::actionSaveMetadata()` — that method name doesn't exist), via `PackageAuthoringService::updatePackage()` |

## 8. Filesystem Impact

**Created**: `packages/{handle}/manifest.json` at package creation/import.
**Modified**: every writer listed in §2, at their respective lifecycle stage.
**Deleted**: on permanent delete only (with the whole `packages/{handle}/` directory).
**Never touched**: nothing outside the owning package's own manifest — no cross-package manifest writes exist.

## 9. Events

None dispatched directly by manifest read/write — the *callers* dispatch their own events (`ResourceImportedEvent`, `VersionCreatedEvent`) after writing.

## 10. Validation and Safety

`PackageManifest::validate()` — Yii/Craft `Model` validation via `defineRules()`. Only `type`/`handle`/`name`/`version`/`schemaVersion` are `'required'`; every other field is `'safe'` (accepted, never blocking). This asymmetry is the entire backward-compatibility mechanism.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| `manifest.json` missing | `PackageReader::readPackage()` throws `Exception("Package manifest not found at: ...")` |
| `manifest.json` invalid JSON | Throws `Exception("Invalid JSON in manifest at: ...")` |
| Required field missing | `$manifest->validate()` returns false; caller throws with the Yii error messages |
| Unknown `type` value | `createPackageInstance()` throws `Exception("Unknown package type: ...")` |
| Extra/unknown key present | Silently ignored — `Model`'s constructor only sets matching public properties |

## 12. Developer Change Guide

If adding a new manifest field: add a plain public property with a safe default, add it to the `'safe'` rule in `defineRules()` — never `'required'` unless you are certain every existing package on every installation already has it (in practice: never, for an existing field). Write a test in `PackageManifestTest.php` proving a legacy manifest (without the field) still loads with the default.

## 13. Related Features

`06_PACKAGE_ARCHITECTURE.md`, `21_FRONTEND_FILE_OWNERSHIP.md` (for `ownedFiles`), `17_PACKAGE_VERSIONING.md` (for `version`).

## 14. Known Limitations

None confirmed — the backward-compatibility mechanism has held for every schema generation observed in this codebase's history.
