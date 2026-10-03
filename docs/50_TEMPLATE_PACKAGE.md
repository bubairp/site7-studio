# 50. Template Package (format v2)

## 1. Purpose

A Template package is one page of the dev site (rp-craft) with its content exactly as stored, installed onto a site set up from the page's Theme (`49`). It is the third Library layer: **Theme** (structure, code, settings) → **Section packages** (blocks, `14` §15) → **Templates** (pages) → Starter Kit (Theme + Templates + demo content, roadmap step 5).

Format v1 Template packages (`15`, `PageImportService` / `TemplateGeneratorService`) keep working as before. They capture scalar field values and a block outline, so they lose plugin fields, relations and nested content. Format v2 is a separate path, chosen by the presence of `template.json`.

## 2. Package format (`packages/template-<...>/`)

| File | Content |
|---|---|
| `manifest.json` | `type: "template"`, `requires: {themes: [<theme>], sections: [<block packages>]}`, `sourceSection`/`sourceSectionType`/`sourceEntryType`, `category` = section name, `pricingType: "free"` |
| `template.json` | `formatVersion: 2`, the page (`entryUid`, `section`, `sectionUid`, `sectionType`, `entryType`, `title`, `slug`, `uri`), `blocks` count, content counts |
| `content/` | `SiteKitContent` format (`48` §10): the page entry, every entry nested in it, and the assets/categories/tags they relate to, with asset files |
| `content/links.json` | Relations between this page's content and other live pages: `{sourceUid, targetUid, row}` |

**Handle:** `template-<single section>` (`template-contact`), otherwise `template-<section>-<slug>` (`template-services-web-development`). If that handle already belongs to another page (same slug under another parent in a tree section), the page's URI is used: `template-<section>-<uri with / as ->`.

**Builds are staged:** each builder (Template, Theme, kit) writes `packages/<handle>.building/` and swaps it in only when the build succeeds. A failed rebuild leaves the package, with its version and price, as it was.

**`requires.sections`:** the Section packages of the blocks the page uses, found by matching entry type UIDs against the Library's v2 `schema.json` files:
- Every page-builder block must be in the Library, or the build fails.
- A Library block nested deeper (a CTA Banner inside another block) is required too, because the outer block's template includes it. Without it, the page fails with "Unable to find the template `_blocks/ctaBanner`".
- Only exported blocks count, so soft-deleted leftovers are ignored.

**Size:** built page packages carry full-size images. For rp-craft, 68 pages come to about 250 MB, with images repeated across pages. They are build output and are git-ignored (`/packages/template-*/`).

## 3. Element IDs: the Library range

Content keeps the dev site's element IDs (`48` §10). IDs also appear inside stored values: reference tags `{entry:5014@1:url}`, and plugin fields such as the Contact page's form choice. Remapping would mean knowing every field type's storage.

To keep IDs safe:
- `ThemeInstaller::apply()` calls `SiteKitContent::reserveLibraryIds()` before anything creates elements. It sets `AUTO_INCREMENT` of every core content table that has one (`elements`, `elements_sites`, `relations`, `structureelements`) to `LIBRARY_ID_LIMIT` (10,000,000).
- Content the site creates itself gets IDs from 10M up. Library content (dev-site IDs) stays below.
- The builder refuses an element at or above the limit.
- A Template install refuses a site whose next element ID is below it (`libraryIdsReserved()`).

MySQL 8 caches `AUTO_INCREMENT` in `information_schema` for a day, so the check sets `information_schema_stats_expiry = 0` first.

## 4. Links between pages

A page's relations to another page (Link/Entries fields, such as the "Contact us" buttons) can't travel in `relations.json`, because the other page may not be installed. Both packages carry the relation in `links.json` with both ends' UIDs. On import, `importLinks()` adds every link whose two ends now exist here (same ID and UID) and isn't here yet. Install order doesn't matter: whichever page arrives second completes the link.

## 5. Console

```
php craft site7-studio/template/build contact                    # single
php craft site7-studio/template/build services/web-development   # section/slug, or an entry ID
php craft site7-studio/template/build-all [--theme=<handle>]     # every live entry of sections with URLs
php craft site7-studio/template/install template-contact
```

`--theme` defaults to the Library's only Theme package.

## 6. Install (`PackageManagerService::installPackage`, template branch)

1. **`TemplateInstaller::preflight()`**: MySQL, the required Theme package is `enabled`, and the Library ID range is reserved. Any failure throws before anything changes.
2. **Existing cascade:** required Section packages are installed and enabled, which links their blocks into the page builder.
3. **Inside the install transaction, `installContent()`:**
   - `SiteKitContent::missingStructure()` lists structure (sections, entry types, fields, layouts...) the content needs but this site lacks, and stops if there is any.
   - Then `import()` in merge mode (`48` §10). For a Single, the incoming entry replaces the one Craft created.
4. The package becomes `enabled`. Removing it doesn't delete the page.

**Structure sections:** nodes keep the source's tree positions (`lft`/`rgt`) and share the source's root node. Import refuses a structure whose root was created on this site, meaning entries were added there before the Template.

Pages of one source tree can arrive in any order, since nesting is fine. A node whose position this site's tree can't take is placed with Craft's Structures service instead (`SiteKitContent::nodesToPlace()`): outside the root's range, the same `lft` or `rgt` as another node, or a crossing range (a page the dev site added between existing ones). It is appended under its parent page (exported as `_parentUid`) or at the end of the root.

## 7. Verified

2026-10-02, fresh DDEV Craft 5.10.8.1 (site7-fresh): Theme, then all 68 rp-craft Template packages, one `template/install` each.

**Install:**
- 68/68 installed in 112 s, pulling in the Section packages they need.
- The site's own rows start at 10,000,025, and no Library content landed at or above 10M.
- No pending project config changes afterwards.

**Pages:** all 68 return 200, the same as rp-craft. 53 have exactly rp-craft's visible text, apart from the site name and the menus (Starter Kit content). The other 15 differ only in:
- visitor reviews/comments (blog and product pages, and product lists sorted by rating),
- the HTML sitemap plugin's link list (plugin data),
- the order of related posts on four blog posts, probably posts tied on date (seen before in `48` §10).

**Rules found on the way:**
- The Theme must carry every field and entry type (`49` §2).
- Nested Library blocks must be required (§2).
- Template handles must not use `page-`, which is a block's name (`page-banner`).

## 8. Known limitations

- A Template installs its page once (same IDs). "Create another page from this template" is a separate feature.
- A Template needs a site set up from its Theme (Library ID range, structure). Installing one on another site fails preflight.
- Links to pages that aren't installed stay empty. Reference tags in text keep pointing at the missing ID until that page is installed.
- Menus (simple-rp-menu) point at pages; they belong to the Starter Kit's content.
- Content import is MySQL-only. There is no CP screen yet, and no signing or Commerce24 distribution yet.

## 9. Important classes

`services/template/TemplateBuilder`, `TemplateInstaller`, `services/sitekit/SiteKitContent` (`export(..., $entryIds)`, `links()`, `importLinks()`, `missingStructure()`, `reserveLibraryIds()`), `console/controllers/TemplateController`. Tests: `tests/unit/services/template/TemplatePackageTest`.
