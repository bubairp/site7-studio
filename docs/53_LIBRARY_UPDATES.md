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

**A page is kept whole (2026-10-22).** Content elements are grouped by their page: a block's `primaryOwnerId` leads to its page, a nested block's to its block (`LibraryUpdater::pagesKeptWhole()`). When any element of a page is kept as edited here (the page's title or any of its blocks), none of that page's elements are updated, added or trashed, and the report says `"Title" (whole page: edited here, N upstream changes not applied)`. Before this, a customer's edited title stayed while the author's replaced blocks arrived beneath it (a renamed blog post showed the customer's title over a different article). Elements without blocks (images, tags, categories) keep the per-element rule. This also applies to the Theme's settings singles (header, footer): one edited block keeps the whole single.

**Shared fields.** The Theme carries every field (`49` §2), and blocks share fields (`plainText` is in many). So a field's live config may be what the Theme or another block installed, not this package's version. Live counts as **untouched** when it matches what **any** installed Library package ships for that item (`libraryConfigHashes()`).

**Content comparison** (`SiteKitContent::signatures()`): one hash per element over its rows in the core content tables, excluding:
- structure nodes (tree positions move as a site adds entries);
- timestamps and folder IDs;
- site IDs and site references in text, which are normalised (a Theme maps the source site to the target's primary site).

JSON text is compared by value, not by text: MySQL's JSON column reformats it, while rp-craft stores compact text. Without this, every page looked edited.

Field values (`comparableContent()`, 2026-10-16, `43` #65): an empty value (`null`, `''`, `[]`, `false`) counts as no value, since Craft writes one for every layout field whenever an entry is saved. The **installed version** is read as this site would hold it now (`LibraryUpdater::asOnThisSite()`): values of fields the site no longer has and references to elements trashed or missing here are left out, along with their relation rows. What an earlier update changed on the site is then not mistaken for the customer's edit.

## 3. Author side

`library/publish` (`52` §3) versions through the plugin's one versioning path (CLAUDE.md invariant 3):
- **Changed** (the package directory's checksum differs from its newest row in `site7_package_versions`): it gets a new version from `VersionManagerService::createVersion()`, which bumps from the highest version ever recorded (never backwards), writes the manifest, archives it and records the row.
- **Unchanged:** published only if that version hasn't reached Commerce24 yet (publish history).
- **No version history yet:** exported and recorded at its current version.

```
php craft site7-studio/library/publish [--bump=patch|minor|major] [--notes="…"] [--force]
```

- Release notes go into the catalog metadata (`releaseNotes`).
- Theme, Template and kit builders keep a package's version and `pricingType` on rebuild.
- The kit no longer writes `builtAt`, so an unchanged rebuild is identical.

**No Library updates on the author site** (2026-10-23). The author site's blocks and pages come from its own templates and reach its servers by deploy, but each environment's `site7_packages` versions stay where they were when its Library was filled (`library/download --all`): a deployed copy of the author site listed every published version as an update, and applying them would write package files outside git. `authorSite` (`Settings`, set in the site's `config/site7-studio.php` so every environment has it) makes `LibraryUpdater::availableUpdates()` return nothing and `update()` refuse with `LibraryUpdater::AUTHOR_SITE_NOTE`; Updates shows that note instead of the list. Customer sites never set it.

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
   - **Section package:** each `schema.json` item by the rule above. Changes are applied in dependency order through `ProjectConfig::set()` (normal, then forced), and project config is saved immediately. The package's files - the block template `templates/_blocks/<handle>.twig` and the manifest's `ownedFiles` - go through `PackageFileUpdater`, file by file (2026-10-26, `43` #67):

     | On this site | New version | Result | Reported as |
     |---|---|---|---|
     | Unchanged since installed | Changed | Replaced | updated |
     | Missing, never installed | Has it | Added | added |
     | Edited | Changed or same | Kept, never overwritten | kept your version |
     | Already the new version | - | Left; tracking row brought up to date | unchanged |
     | Unchanged | Dropped it (or the block was renamed) | Deleted | removed |
     | Edited | Dropped it | Kept | kept (removed upstream, edited here) |
     | Deleted here | Has it | Not recreated | deleted on this site, not recreated |

     "Since installed" is the old version's file; its tracking row (`16`, path relative to the Craft root) stands in when that version had no such file, and also marks a file this site deleted. Every write is read back and compared before its row is saved; a mismatch fails the package update, which can then run again. Owned files keep install's guards: frontend source folders only, one owning package per file. Fields, entry types and content are never deleted by a file removal.
   - **Template package:** the page's live state is exported in the same format and compared element by element. Replaced and new elements go through `SiteKitContent::import($dir, $onlyIds, $replaceIds)`. With foreign key checks off, a replaced element's own rows are swapped without cascading to its nested entries, to its structure node (kept) or to relations pointing at it. Cross-page links reconnect through `links.json`. **Blocks first (2026-10-15, `43` #53):** blocks the new version needs (`requires.sections`) that aren't installed here are downloaded and installed before the content is applied. If the site's plan doesn't include one of them, the page update is **skipped** (reported as skipped, not failed) and the page keeps its installed version. `library/updates` and the CP Updates screen show both per page: "also installs …" and "skipped unless the plan includes …".
   - **Theme package** (`ThemeUpdater`). The new version must be for this site's Craft version, otherwise it stops ("Update Craft first").
     1. **Code files:** every file under `files/`, mapped to the site root as the install copied it (`config/project/` excluded), goes through the rule. A file gone from the Theme is deleted if unchanged here.
     2. **Composer:** if `composer.json`/`.lock` were updated, `composer install` and `migrate/all` run as subprocesses. If the customer changed them, they're kept and a note says to merge by hand. The written files keep the site's Site7 Studio and any installed plugin the new Theme dropped (`49` §2d), and Composer never runs with files that would remove Site7 Studio (`43` #51).
     3. **Plugins:** `plugin/install` runs for each plugin the new version adds.
     4. **Structure and settings, in a new process** (`library/apply-theme`), because Composer may have replaced `vendor/`:
        - the structure items, adjusted to this site (`forThisSite()`). The page-builder field is skipped: every Section package links its block into it, so this site's copy always differs.
        - plugin settings of installed plugins, and CP element sources, each as one item;
        - settings content (the Theme's sections, plus wheelform tables).
     5. **Frontend:** `npm install` + `npm run build` when a file under `frontend/` or `templates/` changed.

     Steps 2 and 5 run for every file the Theme changed that this site now has as the new version has it, whichever run wrote it. So a retry after a failed update still runs Composer and the build. Plugins already installed are skipped.
   - **Library Starter Kit:**
     - Templates the new version requires that aren't installed are downloaded (signed) and installed with their blocks.
     - **A renamed page** (2026-10-16, `43` #64): a new Template handle whose page (`entryUid`) an installed Template package already builds is the same page under its new slug. It's updated from that package (Template rule above, the old package as the baseline), then the new package is recorded as installed (`installPackage($handle, false)`: its content isn't imported again, which would add back the blocks of a page kept whole, §2) and the old one set back to `available`.
     - Its content goes through the rule: demo entries element by element, and the menu and sitemap plugin tables each as one item (`pluginTableSignatures()`). Changing any menu item here keeps the whole menu table. The installed and incoming tables are compared without the rows whose section or category group this site doesn't have (`SiteKitContent::hereOnly()`), because the import left those out (2026-10-27: `seo_sitemap` on site7-qa had 6 of the kit's 13 rows and was kept as "edited here").
   - **Order** within one run: Theme, Sections, Templates, kit.
5. **If downloading or applying fails,** the previous Library copy is restored, so running the update again compares against what the site really has. Items already applied then count as done.
6. **A failed Theme update (2026-10-15, `43` #52):** before writing anything, `ThemeUpdater::backupFiles()` copies every site file the installed or the new version ships, plus `composer.json`/`.lock`, to `storage/site7-studio/update-backups/`. On any failure, `restoreFiles()` puts them back (files the update added are deleted), and if Composer ran, `composer install` runs again with the restored lock. The rest of the run is not attempted (blocks, pages and kits are built on the Theme). The database is **not** restored automatically, because that would also undo content edited while the update ran; the failure message names the run's database backup and the `php craft db/restore <path>` command. The backup folder is removed after a successful update. QA: `SITE7_TEST_FAIL_THEME_UPDATE=structure` (Craft devMode only) forces the structure step to fail.

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

- **No merge within one item.** When the customer edited an element or a field config, the whole item is kept, even if the author changed a different part of it. For content the item is the whole page with its blocks (§2).
- **Structure removed upstream** (a field dropped from a block) is never deleted.
- **No rollback command.** Use the database backup and `library-baselines/`.
- **Sections are updated one package at a time.** A field shared by several packages takes the version of whichever is updated last, unless the customer changed it.
- **Plugin tables are compared whole.** One edited menu item keeps the whole menu table.
- **A Craft version change can't arrive as a Theme update.** Update Craft on the site first, then the Theme.
- **New Composer packages aren't installed when the customer has changed `composer.json`/`.lock`.** The update reports it instead.
- **Version history is the author site's database.** Restoring an older database snapshot on the author site rolls the recorded versions back; the next bump can then be lower than what Commerce24 already has, so installed sites see no update. Publish with a higher `--bump` in that case.
- **New pages in tree (Structure) sections** that don't fit this site's tree are appended under their parent page, or at the end of the root when there is no parent. Their order among siblings can differ from the dev site's (`50` §6).

## 7. Important classes

`services/library/LibraryUpdater` (`applyItems()`, `applyContent()`), `services/theme/ThemeUpdater` (`updateTheme()`, `applyFiles()`, `applyStructureAndContent()`), `LibraryDistribution::publish()` (versions, `bumpVersion()`), `services/sitekit/SiteKitContent` (`signatures()`, `exportToDir()`, `import(..., $onlyIds, $replaceIds)`), `controllers/UpdateWizardController::actionUpdateLibrary()`, `console/controllers/LibraryController` (`updates`, `update`). Tests: `tests/unit/services/library/LibraryUpdaterTest`.
