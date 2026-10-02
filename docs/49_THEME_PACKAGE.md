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

## 3. Console

```
php craft site7-studio/theme/build "RP Craft Theme"     # on the dev site
php craft site7-studio/theme/validate rp-craft-theme    # on the target
php craft site7-studio/theme/install rp-craft-theme
```

`theme/apply <handle>` and `theme/ensure-singles` are internal steps of `install`. No CP screen yet.

## 4. Install (`ThemeInstaller::installTheme`)

**Validate** (`validateTheme`). Checks that the package is `type: theme` and that Craft's version is the same as the build site's. The target must be fresh (no sections, no entries). Path repositories must exist, and the database must be MySQL when the theme has content. A missing npm or `.env` keys only produces warnings.

**Steps:**

1. Backup composer files, `config/`, `templates/`.
2. Copy `files/`, then `composer install` and `migrate/all` (shared with `SiteKitInstaller`).
3. `plugin/install` each plugin.
4. **Structure** (`apply`, subprocess). First `SiteKitContent::reserveLibraryIds()`: this site's own new rows get IDs from 10,000,000 up, so the Library's content never collides with them (`50` §3). Then `ThemeSchemaService::install`: `forThisSite()` swaps the source site/site-group UIDs for the target's primary ones; items are `set` in dependency order, then `set` again with force so cycles resolve. An existing item at the same path is reused and never modified, and a different item with the same handle stops the install before anything changes. Then plugin settings, element sources, and Site7's `matrixFieldUid` (page-builder field) are saved, and the package record is set to enabled.
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
