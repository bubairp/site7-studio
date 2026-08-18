# 46 — Create-from-Template Section Eligibility and Source Page Type

## 1. Purpose

Document two related fixes to the "Create Page from Template" flow (`13_TEMPLATE_ARCHITECTURE.md`)
and the Library UI, found while live-testing "Import Existing Page" (`15_IMPORT_EXISTING_PAGE_AND_
WEBSITE.md`, `45_AUTO_CAPTURE_ASSETS_AND_SECTIONS.md`):

1. A correctness bug - "Create Page from Template" offered Single-type Sections (e.g. a site's
   one-off "Contact" page) as a valid target, even though a Single Section already has exactly one,
   permanently-existing Entry and can never accept a newly created one.
2. A visibility gap - nothing in the Library showed what *kind* of page (Single/Channel/Structure)
   a Template was originally captured from, which is exactly the information that would have made
   bug #1 obvious to a user before hitting it.

## 2. What It Does

**Eligibility fix**: `TemplateInsertionService::getEligibleEntryTypes()` now skips any Section
whose `type === craft\models\Section::TYPE_SINGLE` before even checking whether its Entry Type
carries the Site7 Matrix field. Channel and Structure Sections are unaffected. This is the only
filter added - no attempt is made to match the *installing* Section's type against the template's
*source* Section's type (e.g. "only offer Channel targets for a Template captured from a Channel
page"); Single is excluded categorically because it's the one case where "create a new page here"
is never valid, not because of a source/target type-matching policy.

**Source page type capture + display**: `PackageManifest::$sourceSectionType` (`'single'|'channel'|
'structure'`, i.e. a raw `craft\models\Section::TYPE_*` value) is captured alongside the existing
`$sourceSection` (handle) at the same two call sites that already wrote `sourceSection`. It's
surfaced in the Library list view (both table and card layouts, template packages only) and on the
package detail page ("Source Page Type" field, template packages only, only when the manifest
actually has a value - packages captured before this change simply omit the row rather than
showing a blank one).

## 3. Current Status

Implemented and live-verified (see §9). Small, self-contained fix - no follow-up work identified.

## 4. Architecture

```
TemplateGeneratorService::generateFromEntry() / PageImportService::importNativeContent()
   ↓ (at manifest-build time, same site as $entry->getSection()?->handle already read)
   'sourceSection' => $entry->getSection()?->handle
   'sourceSectionType' => $entry->getSection()?->type      ← new
   ↓
manifest.json
   ↓                                              ↓
Library list/detail views                 "Create Page from Template" wizard
(display only - package.manifest          (TemplateInsertionService::getEligibleEntryTypes()
 .sourceSectionType|title)                 now excludes Section::TYPE_SINGLE targets outright)
```

The two changes are independent - the eligibility fix works whether or not a given Template's
manifest happens to have `sourceSectionType` set (older packages captured before this change still
get correctly filtered against Single *target* Sections; they just won't show their own *source*
type in the UI, since that wasn't recorded at capture time).

## 5. Important Classes

**`TemplateInsertionService::getEligibleEntryTypes()`** — `src/services/TemplateInsertionService.
php`. Added a `continue` on `$section->type === Section::TYPE_SINGLE` before the existing
Site7-Matrix-field-layout check.
**`PackageManifest::$sourceSectionType`** — `src/models/packages/PackageManifest.php`. New public
property, validated as `string` (nullable) alongside the existing `sourceEntryType`/`sourceSection`
rule.
**`TemplateGeneratorService::generateFromEntry()`** / **`PageImportService::importNativeContent()`**
— both call sites that already wrote `'sourceSection' => $entry->getSection()?->handle` now also
write `'sourceSectionType' => $entry->getSection()?->type` immediately after.
**`src/templates/library/index.twig`** — table view gets a conditional "Page Type" column
(`currentType == 'template'` only); card view appends `&middot; {type} page` to the subtitle line
when `package.manifest.sourceSectionType` is set. Both read `package.manifest` (the `PackageRecord`
getter that re-reads `manifest.json` from disk on each request - see `07_PACKAGE_MANIFEST.md`),
**not** a bare `manifest` variable - that variable only exists locally inside `package.twig`'s
`content` block (Twig `{% set %}` doesn't leak across `{% block %}` boundaries), a mistake caught
and fixed during this same change (see §7).
**`src/templates/library/package.twig`** — new conditional field in the sidebar/details block,
directly below "Package Type": `{% if package.type == 'template' and package.manifest and
package.manifest.sourceSectionType %}`.

## 6. Filesystem Impact

None beyond the two new manifest.json keys on newly-captured/re-captured Template packages
(`sourceSectionType`). No migration needed - `PackageManifest`'s properties all default to `null`/
empty, so older manifests parse unchanged and simply don't populate the new UI row.

## 7. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Template captured before this change (no `sourceSectionType` in its manifest.json) | Library list/detail views show nothing extra for that package - no error, no blank "Source Page Type: " row |
| User attempts to target a Single Section via "Create Page from Template" | That Section no longer appears in the `getEligibleEntryTypes()` result at all, so it can't be selected in the first place - no runtime error path was needed |
| `manifest` referenced as a bare variable in `package.twig`'s sidebar block (the original mistake made while building this fix) | `Twig\Error\RuntimeError: Variable "manifest" does not exist` - caught via live browser testing, fixed by switching to `package.manifest` (the `PackageRecord` getter), which is valid in every block since `package` itself comes from the controller's render context, not a local `{% set %}` |

## 8. Known Gaps / Deliberately Out of Scope

- **No source/target Section-type matching policy.** Only Single targets are excluded. A Template
  captured from a Channel page can still be created into a Structure Section (or vice versa) if
  that Section's Entry Type happens to carry the Site7 Matrix field - this was judged acceptable
  (Channel vs. Structure both support "create a new Entry", unlike Single) rather than a gap to
  close, but revisit if it turns out to cause confusion in practice.
- **Existing Templates aren't retroactively re-captured** to backfill `sourceSectionType` - it's
  only set going forward, on the next capture/re-capture of a given page.

## 9. Live Verification (2026-08-19, against `rp-craft.ddev.site`)

1. Confirmed the "Contact" Single Section (which does carry the Site7 Matrix field) no longer
   appears as an eligible target after the fix - only Channel/Structure Sections remain listed.
2. Confirmed `package.twig` renders without error for a pre-existing Template package whose
   manifest lacks `sourceSectionType` (no "Source Page Type" row shown, no Twig exception) -
   this caught and fixed the `manifest` vs. `package.manifest` scoping mistake described in §7
   before it reached the user.
3. Library list view (both card and table layouts) confirmed to render without error for the
   `type=template` filter with the new conditional column/subtitle text in place.

## 10. Related Features

`13_TEMPLATE_ARCHITECTURE.md`, `15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md`,
`45_AUTO_CAPTURE_ASSETS_AND_SECTIONS.md`, `07_PACKAGE_MANIFEST.md`.
