# 48 — Full Site Kit

## 1. Purpose

Turn a fresh Craft install into a copy of an existing site (e.g. rp-craft). The Starter Kit (`32`) can't: it adds pages to a site that already has the same schema, and carries no fields, entry types, sections, templates or frontend sources (`32` §14.3).

**Internal tool since 2026-10-02.** Sites are set up and sold through the Library: Theme (`49`) + Templates (`50`) + Library Starter Kit (`51`). The Site Kits CP screen and its actions are Dev Mode only (menu item hidden otherwise). The job progress pages stay open because Library Starter Kit installs use them. The engine (`SiteKitContent`, `SiteKitInstaller`, `SiteKitJobs`) is the base of all three Library layers.

## 2. What It Does

`SiteKitBuilder` packs everything structural from the source site into one zip; `SiteKitInstaller` applies it to a fresh install using Craft's own mechanisms (Composer, migrations, `project-config/apply`) and builds the frontend.

## 3. Current Status

**Phases 1 and 2 implemented** (2026-10-02): structure, code, frontend and content (§10). Console only.

- **Phase 2 (implemented):** content — entries incl. nested Matrix entries, categories, tags, assets + their files, simple-rp-menu menus, Wheelform forms, htmlsitemap settings. Never transactional data (payments, form submissions, AI chat logs, users). Global sets: not yet (rp-craft has none in project config).
- **Phase 3 (not started):** CP build/install screens, large kits (asset files), distribution through Commerce24 + signing (`47`).

Verified 2026-10-02: kit built from rp-craft (1.4 MB), installed on a fresh DDEV Craft 5.10.8.1 in one command (~40 s). Result matched rp-craft exactly — 34 sections, 104 entry types, 170 fields, 1 volume, 2 category groups, 1 tag group, same site UID, 138 templates, no pending project config, `npm run build` output in `web/themes/front`. Pages that need content (header logo, contact form) error until Phase 2.

## 4. Kit format

`storage/site7-studio/site-kits/<handle>.zip`:

```
site-kit.json         manifest
files/composer.json   source's, as-is
files/composer.lock   exact package versions
files/config/project/ project config; stale plugin entries removed
files/config/*        config files except db.php, license.key*
files/templates/  files/modules/  files/frontend/ (no node_modules)  files/web/assets/
```

`site-kit.json`: `schemaVersion`, `handle`, `name`, `builtAt`, `craftVersion`, `plugins` (handles in the kit's project config), `removedStalePlugins`, `pathRepositories`, `configFiles`, `fileCounts`, `envKeys` (names only — values never leave the source).

## 5. CP screen and console

**CP: Site7 Studio → Site Kits** (`site7-studio/site-kits`, `SiteKitsController`, admins only; installing also needs `allowAdminChanges`):
- *Build a kit from this site* — name + "Include content", starts a background build.
- *Kits on this server* — every zip in `storage/site7-studio/site-kits/` with its manifest summary; Download; **Check** (shown on a fresh site) runs `validateKit()` and shows errors/warnings, then **Install**.
- *Install a kit on this site* — upload (capped by PHP's upload limit, shown on the page; larger kits are copied into `storage/site7-studio/site-kits/`), only on a fresh site.
- Build and install run as background jobs (`SiteKitJobs`): the same console command, started detached with `nohup` (HOME/COMPOSER_HOME set, since web server processes may lack them), output in `storage/site7-studio/site-kit-jobs/<id>.log`. The job page polls `site-kits/job-status` every 2 s and shows the log live; polls failing while composer replaces vendor/ are retried.

Verified 2026-10-02 by driving the screens as an admin on a fresh DDEV site: page → Check ("Ready to install: Craft 5.10.8.1, 21 plugins, 599 entries") → Install → job page showed every step → done in ~51 s → rp-craft's pages served.

**Console:**

- `php craft site7-studio/site-kit/build "<Name>"` — on the source site. Writes only the zip.
- `php craft site7-studio/site-kit/validate <kit.zip>` — on the target; changes nothing.
- `php craft site7-studio/site-kit/install <kit.zip>` — on the target (Site7 Studio installed, nothing else).

## 6. Install rules (each from the 2026-10-02 spike)

1. **Fresh targets only** — refused when the site has any section or entry; the kit replaces the whole structure.
2. **Same Craft version** as the source, and packages from the source's `composer.lock` (`composer install`, not `require`). Project config refuses to apply across plugin schema versions (a newer `simple-rp-menu` blocked it).
3. **Every plugin in the kit's project config must be installed by Composer before `project-config/apply`.** A failed apply aborts half way and Craft then rewrites `config/project/` from the half-applied state, deleting the kit's files. The builder drops plugins listed in project config but not installed on the source (rp-craft lists `super-table`, which isn't installed); the installer checks `vendor/craftcms/plugins.php` before applying.
4. The target keeps its own `system` and `email` project config.
5. `.env` keys the source uses and the target lacks are **reported, never written** — an empty `.env` line would override values the host provides (e.g. DDEV's database settings).
6. Steps after `composer install` run as subprocesses (`craft migrate/all`, `craft project-config/apply --force`, `npm ci`, `npm run build`); the installing process still has the old autoloader.
7. Before changing anything, the installer backs up the target's `composer.json`/`composer.lock`, `config/` and `templates/` to `storage/site7-studio/site-kit-backups/<timestamp>/`.

## 7. Private packages

Composer **path** repositories (`plugins/ai-chat`, `plugins/payment-gateway`, `plugins/htmlsitemap`, and `plugins/site7-studio` itself in rp-craft) are local folders of the source project. The kit records them; validation fails unless each folder exists on the target. The agreed direction is repository access: publish each to a Git repository and point the source's `composer.json` at it, so the lock resolves anywhere. VCS repositories (e.g. `simple-rp-menu` on GitHub) already work, given the target can reach them.

## 8. Important Classes

- `services/sitekit/SiteKitFiles` — pure helpers (stale plugins, target-owned settings, path repositories, env keys, zip tree); unit-tested in `tests/unit/services/sitekit/SiteKitFilesTest.php`.
- `services/sitekit/SiteKitBuilder` (`siteKitBuilder`), `services/sitekit/SiteKitInstaller` (`siteKitInstaller`), `services/sitekit/SiteKitContent` (`siteKitContent`), `console/controllers/SiteKitController`.

## 10. Content (phase 2)

**Approach: copy live rows, keep element IDs.** Re-saving every element through Craft's API would need per-field-type conversion (CKEditor, SEO, Matrix Extended, plugin fields) and lose exactness. Instead `SiteKitContent::export()` copies the live rows of the core content tables (`elements`, `elements_sites`, `elements_owners`, `entries`, `entries_authors`, `contentblocks`, `categories`, `tags`, `assets`, `assets_sites`, `structureelements`, `relations`) plus whole plugin tables (`simplerpmenu`, `simplerpmenu_items`, `wheelform_forms`, `wheelform_form_fields`, `sitemaps`). It's sound only because the target is fresh and already has the source's project config:

- **Element IDs are kept**, so nested entries, relations, structure nodes and plugin rows that point at elements (menu items, sitemap rows) stay connected with no remapping. The importer refuses if any incoming element ID is taken.
- **Structural IDs differ per install** (sites, sections, entry types, fields, field layouts, volumes, groups, structures) but their UIDs come from project config, so those columns travel as `@uid:<uid>` and are resolved on the target. Author/uploader columns point at the target's first admin (`@user`); asset folder IDs are remapped (`@folder:<id>`), nested folders created with the source's UIDs.
- **What travels:** live elements (not drafts, revisions or soft-deleted) of Entry/Asset/Category/Tag/ContentBlock, minus nested elements whose primary owner chain doesn't reach a live element, and nested entries whose field is no longer on their owner's field layout (rp-craft: 550 of 1,057 live entries; the rest are leftovers of deleted pages, old drafts and retired fields such as `themeSetup`).
- **Subset and merge** (used by Theme packages, `49`): `export($zip, $sectionUids, $pluginTables)` exports only those sections' entries with their nested entries and related assets/categories/tags, and only the listed plugin tables. Import merges into a site that already has content: elements with the same ID and UID are skipped, the same ID with a different UID is an error, and only auto-created Singles of incoming sections are replaced. Missing sites fall back to the primary site.
- **Leftovers pointing at soft-deleted structural rows** (e.g. a tree node in a structure deleted in 2025) are left out of `structureelements`/`relations`/site rows and counted in `content/meta.json` `skipped`; anywhere else they're an error.
- **JSON columns:** values read back as JSON strings must be decoded before inserting — Yii JSON-encodes whatever goes into a JSON column, and a double-encoded `elements_sites.content` makes every field read as empty (on rp-craft that sent the header template into an endless include).
- **Asset files** are read through each asset's own filesystem (`Asset::getStream()`) into `content/assets/<assetId>` and written on the target with `$volume->getFs()->writeFileFromStream()`.
- Craft's auto-created Single entries on the target are deleted first; the kit's own replace them.
- The import runs in one transaction with foreign key checks off, as a subprocess after `project-config/apply` (`site-kit/import-content`).

- **Site IDs inside text:** reference tags store a numeric site ID (Link fields: `{entry:5014@1:url}` — 33 values on rp-craft). Every exported text value has them rewritten to `@{site:<uid>}` and resolved to the target's site ID on import (`SiteKitFiles::portableSiteRefs()`/`resolveSiteRefs()`). Missing this blanked every "Contact" link label on the fresh site.

`site-kit/build --no-content` builds a structure-only kit.

**Verified 2026-10-02** — kit with content built from rp-craft (158 MB: 599 entries, 124 assets, 7 categories, 8 tags, 397 relations, 8 menus / 62 items, 2 forms, 121 asset files; 2 leftover tree nodes skipped), installed on a fresh DDEV Craft 5.10.8.1 in one command (~40 s). 13 pages compared with rp-craft: all 200 (the 404 page 404); 11 identical in visible text character for character; the blog post has the same words with two related posts swapped (identical title and date, so their order is not fixed); the 404 page differs only by web server name and a dev-mode debug line. First views are slower on the fresh site while image transforms are generated (gallery: 41 s once).

## 9. Known Limitations

- Content import is MySQL-only (it relies on keeping element IDs and on `SET FOREIGN_KEY_CHECKS`).
- Global set content, users, drafts and revisions don't travel; neither does the search index (rebuild with `craft resave/entries --update-search-index` if site search matters).
- Kits with content are large (rp-craft: 158 MB, nearly all asset files) - Phase 3.
- Plugin data beyond the tables in `SiteKitContent::PLUGIN_TABLES` doesn't travel; a plugin that stores structural IDs in its own tables needs adding there with its site/section columns.
- Not a package type: kits aren't in the Library, not exportable as `.s7pkg`, not signed — deliberately kept out of the package engine for Phase 1.
- `site7/studio` itself must resolve on the target the same way as on the source (a path repository in rp-craft).
- Installing over a failed install isn't supported; restore the backup (or start from a fresh install).
