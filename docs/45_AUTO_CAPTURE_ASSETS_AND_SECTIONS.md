# 45 — Auto-Capture Linked Assets and Missing Sections on Page Import

## 1. Purpose

Document two related fixes/extensions to "Import Existing Page" (`15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md`):

1. A crash fix - `TemplateGeneratorService::extractFieldValues()` fatally cast non-stringable
   field-value objects (e.g. `ether/seo`'s `SeoData`) with a raw `(string)`.
2. A feature - importing a page no longer requires the user to have pre-created matching
   Section packages, or to manually re-link images afterward. Linked Assets and
   not-yet-packaged Matrix block Entry Types are now captured automatically.

## 2. What It Does

**Crash fix**: `TemplateGeneratorService::extractFieldValues()` now only casts an object value
to string when it's actually safely stringable (`method_exists($value, '__toString')`), mirroring
the guard `PageImportService::captureNativeFields()` already used. Anything else (SEO data
models, relation queries without a safe `__toString()`, etc.) is silently omitted from
demoContent/entryFields instead of fataling the whole "Save Package"/"Import Existing Page"
request.

**Auto-capture - Assets**: `AssetCaptureHelper` (`src/services/support/AssetCaptureHelper.php`)
captures an Assets field's *value* (which file(s) an editor actually selected), independently of
`ResourceClassifierService`'s Assets == Shared Resource classification (which still governs the
field's *definition* - the Volume it needs must already exist on the installing site, and is
never captured). The selected file(s) are copied into the package at
`preview/assets/{assetId}-{filename}`, and a structured descriptor
(`{'__site7Type': 'assets', 'items': [{filename, file, volumeHandle, folderPath, altText, title,
kind}]}`) replaces the field's raw value in `demoContent`/`entryFields`. On install,
`AssetCaptureHelper::restoreAssetField()` re-links to an existing Asset on the target site (same
filename + Volume) if one already exists, or uploads the package's bundled copy into a new one
otherwise - so re-running an install/update is idempotent, not an accumulating pile of duplicate
uploads.

**Auto-capture - missing Sections**: `TemplateGeneratorService::generateFromEntry()` previously
silently skipped any Matrix block whose Entry Type wasn't already a recognized, installed Section
package. It now calls `MatrixEntryTypeImportService::importFromEntryType()` (the same, unmodified
"Import Existing Section" pipeline) on the fly to generate one, so the block's content is captured
under `requires.sections` instead of being lost.

## 3. Current Status

Implemented and live-tested against real DDEV content (see §9). Scope is deliberately narrower
than "every possible field/relation" - see §8 for what's out of scope and why.

## 4. Architecture

```
Existing Craft Entry
   ↓
TemplateGeneratorService::generateFromEntry() (Site7-content path)
  or PageImportService::importNativeContent() (native-content path)
   ↓
For each Matrix block's Entry Type not yet a Section package:
   MatrixEntryTypeImportService::importFromEntryType() (unmodified) → new Section package
   ↓
extractFieldValues()/captureNativeFields(), per field:
   Assets field           → AssetCaptureHelper::captureAssetField() → copies file(s) into
                             packagePath/preview/assets/, writes a structured descriptor
   Stringable object       → (string) cast, unchanged
   Non-stringable object   → omitted (was: fatal)
   Scalar/null              → unchanged
   ↓
manifest.json (demoContent / entryFields now may contain {'__site7Type': 'assets', ...})
   ↓
Install / "Create from Template" (TemplateInsertionService::createEntryFromTemplate()):
   entryFields loop detects the descriptor (AssetCaptureHelper::isAssetDescriptor()),
   calls AssetCaptureHelper::restoreAssetField() to get real Asset IDs, then setFieldValue()
```

## 5. Important Classes

**`AssetCaptureHelper`** — `src/services/support/AssetCaptureHelper.php`. Static, stateless -
mirrors `PackageArchiveHelper`'s convention. `captureAssetField()`, `isAssetDescriptor()`,
`restoreAssetField()`.
**`TemplateGeneratorService::extractFieldValues()`** — now takes an optional `$packagePath`
(created up-front in `generateFromEntry()`, rather than after content extraction as before, so
Assets fields can be copied while walking the field layout) and safely omits non-stringable
values instead of casting them.
**`TemplateGeneratorService::autoGenerateSectionPackage()`** — new; delegates to
`MatrixEntryTypeImportService::importFromEntryType()`.
**`PageImportService::captureNativeFields()`** — now takes an optional `$packagePath` (null by
default, so `PageUpdateService::diff()`'s read-only preview never mutates the package on disk;
`PageUpdateService::updateInPlace()` and `PageImportService::importNativeContent()` pass a real
path).
**`TemplateInsertionService::createEntryFromTemplate()`** — entryFields restore loop now branches
on `AssetCaptureHelper::isAssetDescriptor()`.

## 6. Filesystem Impact

A page/section package that captured Assets fields now additionally contains
`preview/assets/{assetId}-{filename}` for each captured file. Nothing else about the package
layout changes.

## 7. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Non-stringable field value (SEO, unexpected relation type) | Omitted from demoContent/entryFields; import proceeds |
| Matrix block Entry Type has no Section package yet | Auto-generated via `MatrixEntryTypeImportService`; if that itself throws (e.g. no capturable fields), the block is skipped, matching the pre-existing "unrecognized Entry Type" behavior |
| `generateFromEntry()` fails after packagePath was created (e.g. "no recognized Sections" after all blocks were skipped) | The partially-written package directory is now removed in a `catch`/`finally` before rethrowing - packagePath had to move earlier in the method (to support Assets capture), so this cleanup was added to preserve the existing "never leave stray package dirs on a failed import" guarantee |
| Target site has no Volumes at all on restore | `restoreAssetField()` returns no ID for that field; the field is left unset rather than throwing |
| Target site already has an Asset with the same filename in the same Volume | Reused by ID; no duplicate upload |

## 8. Known Gaps / Deliberately Out of Scope

- **Matrix block-level Assets fields are captured but not restored.** `demoContent` (a Section
  block's own field values) can now contain an asset descriptor, but nothing currently
  reconstructs it back into a live Matrix block - `TemplateInsertionService::
  createEntryFromTemplate()`'s Matrix-block assembly is the pre-existing, separately-confirmed-open
  Craft 5 array-shape bug (`ARCHITECTURE.md`/known landmine #6: `setFieldValue()` on a Matrix field
  with the old Craft-4-style `['new1' => ['type' => ..., 'fields' => ...]]` shape silently drops
  the value). Fixing that is out of scope here per this agent's explicit instruction not to
  conflate the two; only `entryFields` (a page's own top-level fields, not nested block fields)
  round-trips end-to-end today. The demoContent capture (file copy + descriptor) is still
  correct and useful on its own - it means the data isn't lost, and is ready to be wired up once
  the Matrix-block restore bug is fixed.
- **Categories/Tags field VALUES remain uncaptured** (unchanged pre-existing gap, documented in
  `15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md` §14) - only Assets fields got this treatment; Category/Tag
  relation values were explicitly out of this task's scope.
- **`PageUpdateService`'s Site7-content recapture branch** (`recapture()`'s `$hasSite7Content`
  true path, using `EntrySourceHasher::extractScalarFieldValues()`) was NOT wired to
  `AssetCaptureHelper` - that's a separate, independently-reimplemented capture path
  (`PageUpdateService`'s own docblock: "TemplateGeneratorService... is never called or modified
  here"), out of scope for this change. Only the native-content recapture branch (no Site7 Matrix
  content) benefits from Assets auto-capture on Update Package today.
- **Auto-generated Section packages are never rolled back** if the overall page import
  subsequently fails - e.g. if the page has two unrecognized block types and the second one's
  Section auto-generation throws, the first block's already-created Section package is left
  installed. This matches the project's existing "capture what succeeded" stance elsewhere (a
  partial import isn't rolled back) rather than a new gap.
- **No dedicated volume-selection UI/config** - `restoreAssetField()` falls back to the first
  Volume returned by `Craft::$app->getVolumes()->getAllVolumes()` when the captured
  `volumeHandle` doesn't exist on the installing site. Fine for same-Volume-handle installs (the
  common case - Volumes are typically project-wide conventions like `publicAsset`), but a
  target site with genuinely different Volume handles and no fallback match will upload into
  whatever Volume happens to be first.

## 9. Live Verification (2026-08-18, against `rp-craft.ddev.site`)

Reproduced via a console script driving the exact same service methods the Control Panel
controllers call (`PageImportService::importFromEntry()`, `MatrixEntryTypeImportService::
importFromEntryType()`, `AssetCaptureHelper::captureAssetField()/restoreAssetField()`):

1. **Crash fix**: Entry 6254 ("About Us", live `basicSeo` field is an `ether\seo\models\data\
   SeoData` with no `__toString()`) imported without fataling; `basicSeo` correctly absent from
   the captured `entryFields`.
2. **Section auto-generation**: About Us's one Site7 block (`services` Entry Type, not
   previously packaged) triggered `MatrixEntryTypeImportService::importFromEntryType()`,
   producing a new `services-3` Section package; the Template's manifest correctly referenced it
   via `requires.sections` and captured its field values under `demoContent.services-3`.
3. **Asset auto-capture**: Entry 1137 ("General", a project-wide settings entry with three real
   Assets field selections - `favicon`, `logo`, `acceptedCards`) produced a package whose
   `entryFields` contained three `{'__site7Type': 'assets', ...}` descriptors, and whose
   `preview/assets/` directory contained the three actual copied image files
   (`10445-Development.png`, `9743-logo-new.png`, `5317-cards2.png`).
4. **Install-side restore**: `AssetCaptureHelper::restoreAssetField()` tested directly against a
   real asset - (a) restoring against the same site matched the existing Asset by
   filename+Volume with no duplicate upload; (b) restoring a descriptor whose filename didn't yet
   exist on the site uploaded the package's bundled file and created a real new `Asset` element,
   confirming the "fresh install" path works.
5. All test packages/DB rows created during verification were deleted afterward
   (`PackageManagerService::deletePackage()` + `PageImportSourceRecord::delete()`); the plugin's
   package count/table state was confirmed back at baseline.

## 10. Related Features

`15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md`, `14_IMPORT_EXISTING_SECTION.md`,
`43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md` (landmine #6 - Matrix block save array-shape bug).
