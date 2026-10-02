# 48 — Full Site Kit

## 1. Purpose

Turn a fresh Craft install into a copy of an existing site (e.g. rp-craft). The Starter Kit (`32`) can't: it adds pages to a site that already has the same schema, and carries no fields, entry types, sections, templates or frontend sources (`32` §14.3).

## 2. What It Does

`SiteKitBuilder` packs everything structural from the source site into one zip; `SiteKitInstaller` applies it to a fresh install using Craft's own mechanisms (Composer, migrations, `project-config/apply`) and builds the frontend.

## 3. Current Status

**Phase 1 implemented** (2026-10-02): structure, code and frontend. Console only.

- **Phase 2 (not started):** content — entries incl. nested Matrix entries, categories, tags, globals, asset files, simple-rp-menu menus and Wheelform forms. Never transactional data (payments, form submissions, AI chat logs, users).
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

## 5. Console

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
- `services/sitekit/SiteKitBuilder` (`siteKitBuilder`), `services/sitekit/SiteKitInstaller` (`siteKitInstaller`), `console/controllers/SiteKitController`.

## 9. Known Limitations

- No content yet (Phase 2), so content-dependent templates fail on the fresh site.
- Not a package type: kits aren't in the Library, not exportable as `.s7pkg`, not signed — deliberately kept out of the package engine for Phase 1.
- `site7/studio` itself must resolve on the target the same way as on the source (a path repository in rp-craft).
- Installing over a failed install isn't supported; restore the backup (or start from a fresh install).
