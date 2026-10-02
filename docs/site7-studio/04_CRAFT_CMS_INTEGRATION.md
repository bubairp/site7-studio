# 04 — Craft CMS Integration

## 1. Purpose

Explain exactly which Craft CMS APIs SITE7 Studio uses to create/update/remove native resources, and confirm — against the actual code, not assumption — that it uses official APIs rather than direct YAML manipulation.

## 2. What It Does

Wraps Craft's Fields, Entries, Categories, Tags, Volumes, and Project Config services behind SITE7 Studio's own service layer, so package installation/update/removal always goes through Craft's own validated, event-dispatching code paths.

## 3. Current Status

**Implemented** for Fields/Entry Types (Package Engine, `CraftResourceService`) and Asset Volumes/Category Groups/Tag Groups/Section-settings-update (Starter Kit installation, `CraftResourceInstallExecutor`). **Not implemented**: creating a Craft Section from scratch (see 14 below).

## 4. Architecture

```
Package fields.yaml / matrix.yaml
   ↓
CraftResourceService::generateResources()
   ↓
Craft::$app->getFields()->saveField() / getFieldByHandle()
Craft::$app->getEntries()->saveEntryType() / getEntryTypeByHandle()
   ↓
Craft's own event system, validation, Project Config sync (automatic, via Craft itself)
```

## 5. Execution Flow

1. `CraftResourceService::createCraftField(array $def)` — looks up `getFieldByHandle($def['handle'])` first; returns the existing field if found (idempotent), otherwise builds a `craft\fields\*` instance from a fixed type map (`FIELD_TYPE_MAP`: PlainText, Number, Lightswitch, Dropdown, Date, Assets [detect-only], Ckeditor [soft dependency, class_exists-guarded], Entries, Matrix) and calls `$fieldsService->saveField($field)`.
2. `CraftResourceService::createMatrixEntryType(array $def)` — same idempotent lookup-by-handle pattern, builds a `craft\models\EntryType` + `FieldLayout` + `FieldLayoutTab` + `CustomField` elements, calls `$entriesService->saveEntryType($entryType)`.
3. Both operations are genuinely idempotent — installing an already-installed package, or a package whose Fields/Entry Types another package already created, never creates duplicates.
4. `CraftResourceInstallExecutor` (Starter Kit installation, see `32_STARTER_KIT_SYSTEM.md`) creates/updates Asset Volumes/Category Groups/Tag Groups via `saveVolume()`/`saveGroup()`/`saveTagGroup()`, and **only updates** an already-existing Craft Section's settings via `saveSection()` — it never creates a Section or its Entry Types from scratch.
5. Project Config changes are never written directly — `ProjectConfigExecutor` (Starter Kit installation only) calls `Craft::$app->getProjectConfig()->rebuild()`, letting Craft itself regenerate `config/project/*.yaml` from live state.

## 6. Important Classes

**`CraftResourceService`**
`src/services/CraftResourceService.php`
Responsibility: create/describe/remove Craft Fields and Entry Types for Section packages; copy `template.twig` to `templates/_blocks/`; install owned-file-adjacent template logic.
Important methods: `generateResources(string $packagePath): array`, `removePackageResources(string $packagePath): array`, `createCraftField()`/`createMatrixEntryType()` (private, idempotent), `describeField()`/`describeFieldLayout()` (read direction, used by every import/discovery service).
Called by: `PackageManagerService::installPackage()`/`deletePackage()`, `MarketplaceService::repairPackage()`.
Dependencies: `Craft::$app->getFields()`, `Craft::$app->getEntries()`.
Side effects: writes `templates/_blocks/*.twig`, creates/updates live Craft Fields/Entry Types.

**`CraftResourceInstallExecutor`**
`src/services/installation/executors/CraftResourceInstallExecutor.php`
Responsibility: Starter-Kit-scope native resource create/update (Volumes/Category Groups/Tag Groups/Section settings only).
Important methods: `execute()` (implements `StepExecutorInterface`).
Called by: `InstallationExecutor` (dispatched by step type).

**`CraftResourceRegistry` / `CraftResourceScanner`**
`src/services/CraftResourceRegistry.php`, `src/services/CraftResourceScanner.php`
Responsibility: read-only, whole-project graph of native Craft resources — the data source for `ResourceClassifierService`, `CraftResourceDiscoveryService`, `DependencyAnalyzer`'s cycle detection.

## 7. Data Model

No SITE7-owned tables here — the resources themselves live in Craft's own `fields`, `entrytypes`, `sections`, `volumes`, `categorygroups`, `taggroups` tables, mutated exclusively via Craft's own service APIs.

## 8. Filesystem Impact

**Created**: `templates/_blocks/{handle}.twig` (see `13_TEMPLATE_ARCHITECTURE.md`).
**Modified**: same, on safe update.
**Deleted**: same, on permanent delete, content-compare guarded.
**Never touched**: `config/project/*.yaml` directly (only via Craft's own `rebuild()`), `templates/site7-components/` (confirmed dead — see `13_TEMPLATE_ARCHITECTURE.md`).

## 9. Events

None SITE7-specific here — Craft's own `saveField`/`saveEntryType`/`saveVolume` etc. dispatch Craft's native events (e.g. `Fields::EVENT_AFTER_SAVE_FIELD`), which SITE7 Studio does not currently subscribe to itself.

## 10. Validation and Safety

- Idempotent lookup-by-handle before every create (§5).
- `removePackageResources()` never deletes a Field/Entry Type still in use: checks `Craft::$app->getFields()->findFieldUsages($field)` and `Entry::find()->typeId($entryType->id)->status(null)->count()` before deleting — skips and reports instead of destroying content.
- `CraftResourceInstallExecutor` skips creating an Asset Volume if its filesystem handle doesn't exist on the target site, rather than failing the whole install.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Field/Entry Type handle already exists | Returned as-is (idempotent), never duplicated |
| Field/Entry Type still in use, deletion attempted | Skipped, reported in the return warnings array, never force-deleted |
| Asset Volume's filesystem missing on target | Skipped for that Volume only, install continues |
| Craft's `saveField()`/`saveEntryType()` returns false | Caller (`installPackage()`) rolls back its DB transaction and calls `removeResources()` to undo anything created in that same call |

## 12. Developer Change Guide

If changing Craft resource creation:
```
CraftResourceService (createCraftField / createMatrixEntryType)
   ↓
PackageManagerService::installPackage()  (caller, orchestrates transaction + baseline recording)
```
Do not add a second Field/Entry Type creation path elsewhere — every Section package install goes through `CraftResourceService` exclusively.

## 13. Related Features

`06_PACKAGE_ARCHITECTURE.md`, `11_PACKAGE_INSTALLATION.md`, `13_TEMPLATE_ARCHITECTURE.md`, `32_STARTER_KIT_SYSTEM.md`.

## 14. Known Limitations

- Craft Section/Entry Type creation from scratch is **not implemented** anywhere in this codebase — `CraftResourceInstallExecutor::applyCraftSection()` only updates an existing Section's settings, by explicit design documented in its own docblock. A target Section must already exist (created manually, or via the Package Engine's own Entry-Type-creation path for Section packages, which is a different, narrower mechanism than a full Craft Section).
- `Assets` field type is detect-only (read direction) — the write direction (creating an Assets field from an imported package) is explicitly out of scope in `FIELD_TYPE_MAP`'s own docblock.
