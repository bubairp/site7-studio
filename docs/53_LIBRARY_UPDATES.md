# 53. Library Updates

## 1. Purpose

When a block or page changes on the dev site (rp-craft) and is published again, sites that installed it from Commerce24 (`52`) receive the new version without losing their own changes.

## 2. The rule

Every item of a package is checked separately: each file, each field / entry type config, and each content element (a page, each block nested in it, each related asset). Three states are compared:

- **baseline**: the version this site installed. It's the package's own copy in this site's Library, kept aside (`storage/site7-studio/library-baselines/<handle>/<version>/`) before the signed new version replaces it.
- **live**: this site now.
- **incoming**: the new version.

`LibraryUpdater::decide()` → `PackageUpdatePlanner::classify()` (`19`):

| This site vs. installed | New version | Result |
|---|---|---|
| unchanged | changed | **updated** |
| changed (customer's edit) | changed or not | **kept**, reported as "kept your version" |
| deleted | — | stays deleted |
| already what the new version has | — | nothing to do |
| — (new item) | added | **added**, unless something different already exists there (then kept) |
| unchanged | removed | content: **moved to the trash** (restorable); structure: kept |

**Shared fields.** The Theme carries every field (`49` §2), and blocks share fields (`plainText` is in many). So a field's live config may be what the Theme or another block installed, not this package's version. Live counts as **untouched** when it matches what **any** installed Library package ships for that item (`libraryConfigHashes()`).

**Content comparison** (`SiteKitContent::signatures()`): one hash per element over its rows in the core content tables, excluding:
- structure nodes (tree positions move as a site adds entries);
- timestamps and folder IDs;
- site IDs and site references in text, which are normalised (a Theme maps the source site to the target's primary site).

JSON text is compared by value, not by text: MySQL's JSON column reformats it, while rp-craft stores compact text. Without this, every page looked edited.

## 3. Author side

`library/publish` (`52` §3) publishes only packages whose directory checksum changed since this site last published them (`storage/site7-studio/library-published.json`). Before exporting, it raises their version:

```
php craft site7-studio/library/publish [--bump=patch|minor|major] [--notes="…"] [--force]
```

- Release notes go into the catalog metadata (`releaseNotes`).
- Theme, Template and kit builders keep a package's version and `pricingType` on rebuild.
- The kit no longer writes `builtAt`, so an unchanged rebuild is identical.

## 4. Customer side

- **Console:**

  ```
  php craft site7-studio/library/updates
  php craft site7-studio/library/update <handle,handle> | --all
  ```
- **CP:** Site7 Studio → **Update** → *Library updates* lists installed Library packages with a newer catalog version, with release notes. **Update selected** runs `library/update` as a background job with the live progress page. The blueprint kit updates (`32`) show below it only when they exist.

**Per package** (`LibraryUpdater::updatePackage()`):
1. The database is backed up once per run (`storage/backups/`).
2. The Library copy is kept aside as the baseline.
3. The new version is downloaded (signature required) and imported into the Library (`install: false`).
4. The update is applied:
   - **Section package:** each `schema.json` item by the rule above. Changes are applied in dependency order through `ProjectConfig::set()` (normal, then forced), and project config is saved immediately. The block template `_blocks/<handle>.twig` is updated through the rule too, and its file baseline (`16`) is recorded.
   - **Template package:** the page's live state is exported in the same format and compared element by element. Replaced and new elements go through `SiteKitContent::import($dir, $onlyIds, $replaceIds)`. With foreign key checks off, a replaced element's own rows are swapped without cascading to its nested entries, to its structure node (kept) or to relations pointing at it. Cross-page links reconnect through `links.json`.
   - **Theme package** (`ThemeUpdater`). The new version must be for this site's Craft version, otherwise it stops ("Update Craft first").
     1. **Code files:** every file under `files/`, mapped to the site root as the install copied it (`config/project/` excluded), goes through the rule. A file gone from the Theme is deleted if unchanged here.
     2. **Composer:** if `composer.json`/`.lock` were updated, `composer install` and `migrate/all` run as subprocesses. If the customer changed them, they're kept and a note says to merge by hand.
     3. **Plugins:** `plugin/install` runs for each plugin the new version adds.
     4. **Structure and settings, in a new process** (`library/apply-theme`), because Composer may have replaced `vendor/`:
        - the structure items, adjusted to this site (`forThisSite()`). The page-builder field is skipped: every Section package links its block into it, so this site's copy always differs.
        - plugin settings of installed plugins, and CP element sources, each as one item;
        - settings content (the Theme's sections, plus wheelform tables).
     5. **Frontend:** `npm install` + `npm run build` when a file under `frontend/` or `templates/` changed.
   - **Library Starter Kit:**
     - Templates the new version requires that aren't installed are downloaded (signed) and installed with their blocks.
     - Its content goes through the rule: demo entries element by element, and the menu and sitemap plugin tables each as one item (`pluginTableSignatures()`). Changing any menu item here keeps the whole menu table.
   - **Order** within one run: Theme, Sections, Templates, kit.
5. **If applying fails,** the previous Library copy is restored, so running the update again compares against what the site really has. Items already applied then count as done.

## 5. Verified (2026-10-03)

**Setup.** rp-craft → mock Commerce24 → site7-fresh, with the kit installed from Commerce24 (empty Library first).

- **Check before testing:** all 68 installed pages compared equal to their Library copy. This found the JSON formatting difference (§2).
- **Round 1 (CP, visible browser), author changes:**
  - accordion template and a field;
  - buttons template;
  - About Us and Web Development titles.

  The customer had edited the buttons template and the Web Development title. Only those 4 packages were published (1.0.1); 93 were unchanged. The Update screen listed 4 updates.
  - Result: accordion template updated; About Us title updated.
  - Kept: the customer's buttons template and Web Development title.
  - Running it again: 0 updates.
  - This round found the end-of-request project config save (now immediate).
- **Round 2 (clean reinstall, then update):** the same results, plus the shared-field case. `plainText` came from the Theme, so it was reported kept. Fixed by `libraryConfigHashes()`.
- **Round 3:** the shared field updates (`Updated in accordion 1.0.3`).
- **Round 4:** the customer changed that field's instructions; the author's 1.0.4 kept the customer's text.
- Project config clean after every round; pages 200.
- **Theme and kit round:**
  - Author changes:
    - Theme: `404.twig`, `500.twig`, `app.css`, the 404 settings single's title;
    - kit: a menu item renamed, and a new page "New Offer".
  - Customer edit: `500.twig`.
  - Published: Theme and kit 1.1.0 (`--bump=minor`) and the new page.
  - Result, in 14 s:
    - Code files: 2 updated, `500.twig` kept, 178 unchanged; the frontend was rebuilt.
    - Structure, settings and settings content: the 404 title updated; the customer's field text from round 4 kept; 385 unchanged.
    - Kit: the new page downloaded and installed (`/new-offer` 200); the menu table updated ("About Site7" in the header and footer).
  - Project config clean, pages 200, and running it again found 0 updates.

## 6. Known limitations

- **No merge within one item.** When the customer edited an element or a field config, the whole item is kept, even if the author changed a different part of it.
- **Structure removed upstream** (a field dropped from a block) is never deleted.
- **No rollback command.** Use the database backup and `library-baselines/`.
- **Sections are updated one package at a time.** A field shared by several packages takes the version of whichever is updated last, unless the customer changed it.
- **Plugin tables are compared whole.** One edited menu item keeps the whole menu table.
- **A Craft version change can't arrive as a Theme update.** Update Craft on the site first, then the Theme.
- **New Composer packages aren't installed when the customer has changed `composer.json`/`.lock`.** The update reports it instead.
- **Publish history:** `library/publish` compares against `storage/site7-studio/library-published.json` on the author site. Deleting that file makes the next publish send everything again at its current version, without raising versions, so installed sites see no update.

## 7. Important classes

`services/library/LibraryUpdater` (`applyItems()`, `applyContent()`), `services/theme/ThemeUpdater` (`updateTheme()`, `applyFiles()`, `applyStructureAndContent()`), `LibraryDistribution::publish()` (versions, `bumpVersion()`), `services/sitekit/SiteKitContent` (`signatures()`, `exportToDir()`, `import(..., $onlyIds, $replaceIds)`), `controllers/UpdateWizardController::actionUpdateLibrary()`, `console/controllers/LibraryController` (`updates`, `update`). Tests: `tests/unit/services/library/LibraryUpdaterTest`.
