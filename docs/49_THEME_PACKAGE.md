# 49. Theme Package

## 1. Purpose

A Theme package is the base layer of a Site7 site, built from the dev site (rp-craft) and installed onto a fresh Craft install that has Site7 Studio. It holds everything a site needs **before** its blocks and pages: structure, templates, frontend, plugins and settings content. Blocks stay separate Section packages (`14` §15), so they can be sold and updated one by one.

Library layers, installed in this order:

1. **Theme** (this doc): structure, code, plugins, settings.
2. **Section packages**: page-builder blocks, linked into the theme's page-builder field.
3. **Template packages**: pages. Not built yet (roadmap step 4).
4. **Starter Kit**: Theme + Templates + demo content. Not built yet (roadmap step 5).

A Theme is not a Full Site Kit (`48`): a kit replaces a site's project config and copies all content. A Theme installs **into** the target site through `ProjectConfig::set()`, never swaps project config, and copies only settings content.

## 2. Package format (`packages/<handle>/`)

| File | Content |
|---|---|
| `manifest.json` | `type: "theme"`, `pricingType: "free"` |
| `theme.json` | `craftVersion`, `plugins`, `pageBuilderField`, `pageBuilderBlocks`, structure counts, `settingsSections`, content counts, `configFiles`, `pathRepositories`, `envKeys` (names only) |
| `schema.json` | Structure (`ThemeSchemaService`): `{formatVersion, sourceSites, sourceSiteGroups, pageBuilderField, pageBuilderEntryTypes, items[{path, handle, config}]}` |
| `plugins.json` | Plugin project config (settings), except site7-studio, plus `elementSources` |
| `files/` | `composer.json`/`composer.lock`, templates (minus the page-builder blocks' `_blocks/<handle>.twig`, which belong to Section packages), modules, frontend, `web/assets`, config files except `db.php`, license, `project/` and `site7-studio.php` |
| `content/` | Settings content in the `SiteKitContent` format (`48` §10) |

**Structure.** Includes everything reachable from the roots, following any UID in a config plus volumes' filesystem handles. The roots are filesystems, volumes, sections, category groups, tag groups, global sets, **every field and every entry type**. So the theme carries the site's whole structure (rp-craft: 170 fields, 104 entry types).

Fields and entry types are roots because templates read fields by handle. `entry.formBorderRadius ?? false` is safe in Craft only while some field or field layout has that handle; otherwise the page 500s ("Calling unknown method"). rp-craft's sitemap template reads handles that exist only on the `contact` block's layout.

**Blocks stay separate.** The page-builder field is captured **with no entry types**, and block templates (`_blocks/<handle>.twig`) are left out. A Section package is what adds its block to the page builder and installs its template; it reuses the entry type and fields that are already there (same UID).

**Settings content links.** Relations from settings content to pages (header/footer links) travel as links (`50` §4) and connect once the pages are installed.

**Settings content.** Covers the entries of every section without URLs: settings Singles (header, footer, general, themeSettings...) and data sections (pricing packages, colour options, fonts, category-like structures). Sections that Guest Entries accepts visitor submissions into (reviews) are left out, because that's demo content (`ThemeBuilder::visitorSectionUids()`). The entries come with their nested entries and related assets/categories/tags (`SiteKitContent::export($zip, $sectionUids, ThemeBuilder::SETTINGS_PLUGIN_TABLES)`), plus wheelform forms (`wheelform_forms`, `wheelform_form_fields`). Page content is not included.

**Secrets.** Never copied. Plugin settings keep their `$ENV_VAR` references, and `.env` keys are listed by name only.


## 2a. Craft versions (2026-10-06)

A Theme, Starter Kit or Template installs and updates on any Craft of the **major** version it was built on: built on 5.10.8.1, it installs on 5.x (`support/CraftVersion`), and Craft 6 needs the Library rebuilt on Craft 6. The install keeps the site's own Craft: when the Theme's `composer.lock` pins another Craft version, the Composer step runs `composer require craftcms/cms:<this site's version> --update-with-all-dependencies` instead of `composer install`. Content rows leave out columns the site's tables don't have. Full Site Kits (`48`) still need the exact version: they replace the whole database.

Limits: a site on an older 5.x than the Library may not know newer field or block settings; and after the Composer step changes `composer.lock`, a later Theme update counts the lock as the customer's own (`53` §6: merge by hand).
## 2b. Built frontend (2026-10-06)

The Theme ships the site's built frontend - the folder holding Vite's manifest (`config/vite.php` `manifestPath`; rp-craft: `web/themes/front`), recorded as `builtFrontend` in `theme.json` (`ThemeBuilder::builtFrontendPath()`). Install copies it and skips npm, so a site works on hosting without npm or offline; `frontend/` still comes along to rebuild with `npm run build`. Theme updates deliver the built files through the file rule (a site that built its own keeps its manifest) and skip npm. Build the frontend on the author site before building the Theme.

## 2c. Base and optional sections (2026-10-07)

A site sees only the sections it uses: built with `--base-sections`, the Theme installs those, and every other section is **optional** - it installs when a page, page pack or Starter Kit needs it (`ThemeInstaller::addSections()`). Blocks already worked this way: the page builder ships empty and each Section package links its block in.

- `theme.json`: `baseSections`, `optionalSections`, `baseContentIds` (the settings content a fresh install imports) and `sectionContent` (optional section => its settings content element IDs). `schema.json` and `content/` stay complete, so a Theme update compares like with like. A rebuild without `--base-sections` keeps the earlier list; a Theme without one installs every section, as before.
- Install: `apply()` leaves the optional sections out (`ThemeSchemaService::withoutSections()`); `theme/import-content` imports the base part.
- `addSections(handles)` creates the missing ones from the Theme's schema (same UIDs), saves a Single so it gets its entry, then imports their settings content. Called by: a Library Starter Kit before its pages (`KitInstaller::kitSections()`: a pack's `sections`, every optional section for a full kit), a Template package before its content (its own section), and a kit update (`LibraryUpdater::applyKit`). Also `php craft site7-studio/theme/add-sections blogs,blogCategories`.
- Links between pages to a section that isn't here are skipped, as before; they're added when the other page arrives.
- A section is never removed: not by a plan downgrade, a removed pack or a Theme update. A Theme update leaves out optional sections this site doesn't have, and their settings content (`applyContent()` `$skipIds`).

rp-craft's base sections: Home, Contact, Standard Pages, Sitemap, Page Error, Page Maintenance, Page Not Found, General, Header, Footer, Theme Settings, Color Library, Font Library, Additional CSS & JS, Google Structure Data, LLMs Text. The 18 others come with the packs (`51` §2a).

## 2d. Composer: Site7 Studio stays the site's own, plugin folders travel (2026-10-08)

A site can install Site7 Studio from Git, a local folder or Packagist, so the Theme never decides how (`ComposerFiles`, unit-tested):

- **Build:** the Theme's `files/composer.json`/`composer.lock` are the author site's **without Site7 Studio** - its `require` line, the repository it comes from and its locked entry (`ComposerFiles::withoutPackage()`). Every other local path repository (rp-craft: `plugins/rp/ai-chat`, `plugins/rp/htmlsitemap`, `plugins/rp/payment-gateway`, the `craftcms-plugins` repo) is **copied into the Theme** (`files/<path>`, without `.git`/`vendor`/`node_modules`) and set to install as a copy (`"symlink": false`, also in the lock). `theme.json` `bundledPaths` lists them.
- **Install:** the folder check accepts a path the Theme brings (or Site7 Studio's own, from a Theme built before this). The bundled folders are copied to the site. Before `composer install`, the site's own Site7 Studio - require line, repository, locked entry - goes back into the Theme's files (`ThemeInstaller::withThisSitesPlugin()`, `ComposerFiles::withPackageFrom()`), so Composer keeps the plugin as installed.
- **Updates:** composer.json/lock go through the file rule compared without Site7 Studio on all three sides (`ThemeUpdater::withoutSite7()`), otherwise every site would look edited. A bundled plugin's code changes reach `vendor/` when its `version` (so the lock) changes.
- The lock's `content-hash` is recomputed with Composer's own algorithm (`ComposerFiles::contentHash()`, checked against rp-craft's lock).

## 3. Console

```
php craft site7-studio/theme/build "RP Craft Theme" [--base-sections=home,contact,...]   # on the dev site
php craft site7-studio/theme/validate rp-craft-theme    # on the target
php craft site7-studio/theme/install rp-craft-theme
php craft site7-studio/theme/add-sections blogs,blogCategories
php craft site7-studio/library/rename rp-craft-theme "Site7 Theme"   # the name customers see; builds keep it
```

`theme/apply <handle>`, `theme/ensure-singles` and `theme/import-content <handle>` are internal steps of `install`. No CP screen yet. The build argument only decides the handle: the Theme is named "Site7 Theme" and the full kit "Site7 Full Kit" (2026-10-07), handles `rp-craft-theme` and `rp-craft-starter-kit` unchanged.

## 4. Install (`ThemeInstaller::installTheme`)

**Validate** (`validateTheme`). Checks that the package is `type: theme` and that Craft's version is the same as the build site's. The target must be fresh (no sections, no entries). Path repositories must exist, and the database must be MySQL when the theme has content. A missing npm or `.env` keys only produces warnings.

**Steps:**

1. Backup composer files, `config/`, `templates/`.
2. Copy `files/`, then `composer install` and `migrate/all` (shared with `SiteKitInstaller`).
3. `plugin/install` each plugin, skipping those already installed (a re-run after a stopped install).
4. **Structure** (`apply`, subprocess). First `SiteKitContent::reserveLibraryIds()`: this site's own new rows get IDs from 10,000,000 up, so the Library's content never collides with them (`50` §3). Then `ThemeSchemaService::install`: `forThisSite()` swaps the source site/site-group UIDs for the target's primary ones; items are `set` in dependency order, then `set` again with force so cycles resolve. An existing item at the same path is reused and never modified, and a different item with the same handle stops the install before anything changes. The one exception is the empty `site7Components` field the old Setup screen created (`isSetupPlaceholder()`): the Theme's field of that handle replaces it. Then plugin settings, element sources, and Site7's `matrixFieldUid` (page-builder field) are saved, and the package record is set to enabled. **The Theme's page builder always wins** over a field set before, because its Section blocks are linked into it. The job log says so ("Page builder: 'Matrix Content Blocks' (matrixContent), from the Theme - replaces … set before"). On a site with no page builder, the Dashboard and Library point to Install a Starter Kit.
5. **Singles** (`ensureSingles`, subprocess). `Entries::saveSection()` for every Single.
6. **Settings content** (`site-kit/import-content`, subprocess), in merge mode (`48` §10).
7. `npm run build`.

Steps after `composer install` run as subprocesses because the installing process still has the old autoloader and plugin list.

## 5. Rules learned in live testing (2026-10-02)

- **Singles.** Craft creates a Single's entry only when an **existing** section is saved through `Entries::saveSection()`, not when the section arrives through `ProjectConfig::set()`. Creating entries by hand in `apply()`'s process gave duplicate entries and `uri` NULL. `saveSection()` in a fresh process gives one entry with its URI. It reuses an existing entry and deletes extras, so the step is safe to re-run. When counting a Single's entries, exclude revisions (`elements.revisionId`): each save adds one.
- **Settings singles that reference plugin data** need that data: Home's form block needs wheelform forms, so they are settings content.
- **Retired fields.** A nested entry whose field is no longer on its owner's layout is dropped from content (`48` §10), otherwise its field layout UID is missing on the target.

## 6. Verified (site7-fresh, rp-craft-theme)

- Install takes about 1m10s.
- First version: 241/241 structure items identical to rp-craft. Since fields and entry types became roots, the fresh site has all 170 fields and 104 entry types, the same as rp-craft.
- All 27 Section packages install on top, and the page builder gets the same 27-block set.
- Each Single has exactly one entry.
- With the Template packages on top, all 68 pages return 200 (`50` §7).
- rp-craft is unchanged by the build.

## 7. Known limitations

- **Pages need their Template packages.** For example, `entries/singles/contact.twig` reads `entry.selectForm.id`, which is page content (`50`).
- **Visitor content travels with the Starter Kit, not the Theme.** That's sections Guest Entries accepts submissions into (reviews).
- **Guest Entries' author setting names a source-site user.** Users don't travel, so set it on the new site.
- **Fresh installs only.** There is no theme update or uninstall.
- **Exact Craft version required.** Installing on another version fails validation.
- **Settings content needs MySQL.**
- **CLI only.** No CP screen, no signing or Commerce24 distribution yet.

## 8. Important classes

`services/theme/ThemeBuilder`, `ThemeSchemaService`, `ThemeInstaller` (extends `sitekit/SiteKitInstaller`), `services/sitekit/SiteKitContent` (subset export, merge import), `console/controllers/ThemeController`. Tests: `tests/unit/services/theme/ThemeSchemaServiceTest`.
