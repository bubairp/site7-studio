# 60. Design packages (switchable designs)

## 1. Purpose

A site keeps its content and CMS structure and can switch its whole look between installed **designs**, the way WordPress switches themes. A design is **frontend only**: templates, CSS/JS, fonts and images. It reads the same fields as every other design and never adds fields, sections or entry types; anything new in the CMS goes into the Theme (`49`) for every design.

The **default design** is the site's own `templates/` and `frontend/` (on rp-craft: "Editorial", handle `default`). It is never a package and is always there to fall back to.

Status (2026-10-22): phases 1 (loader, setting, preview links) and 2 (package type, build, catalog, versions endpoint) are built. Install/update, the Appearance screen and release steps follow (§7).

## 2. A design on disk

```
designs/<handle>/
  design.json      name, version, contract version, reference
  DESIGN.md        the design's rules (colours, type, components)
  templates/       only the files it changes, same paths as templates/
  src/css/<handle>.css   Tailwind entry: the site's app.css + the design's role tokens
web/designs/<handle>/    the build (Vite manifest, CSS, JS, fonts)
```

Built in `frontend/` with `DESIGN=<handle> npx vite build --config vite.design.mjs`: the same entries as the site's own build, with `./css/app.css` swapped for the design's CSS, so templates keep calling `craft.vite.script('src/index.js')`.

Designs restyle through **role tokens** (rp-craft `frontend/src/css/theme.css`, `DESIGN.md` §2): `--surface`, `--surface-alt`, `--surface-dark`, `--line`, `--text-strong/-body/-muted`, `--on-dark`, `--primary` (CTA fill), `--on-primary`, `--accent` (accent text and strokes), heading weight/tracking/`<em>` style, `--quote-weight`. CMS library colours that are the default design's palette render as roles through the host's `|roleColor` Twig filter.

## 3. Which design a request renders

`services/design/DesignLoader::register()`, called from `Site7Studio::init()` after Craft has initialised. First match wins (`DesignLoader::resolve()`, unit-tested):

1. a **signed preview link**, `?site7Design=<token>` (`DesignLoader::previewUrl()`, console `site7-studio/design/preview-url <handle> [--hours=24]`): `Security::hashData()` over the handle and an expiry. Works without Dev Mode or a login, so a client can look before anything is activated;
2. `?design=<handle>` in **Dev Mode**;
3. the **`activeDesign` setting** (project config). `SITE7_DESIGN` in `.env` overrides it through `config/site7-studio.php`; `default` means the site's own templates;
4. the default design.

A handle must match `^[a-z0-9][a-z0-9-]{0,63}$`; a design without `designs/<handle>/templates` renders the default.

With a design chosen:
- `designs/<handle>/templates` becomes the site templates path **and** the `@templates` alias, and `templates/` is registered as a site template root without a prefix, so a missing file falls back to the site's own. Setting only the path is not enough: Craft resets it to `@templates` on every template-mode change, and a plugin rendering a CP template mid-page (the SEO plugin, in `<head>`) would switch the rest of the page back.
- The Vite plugin is pointed at `web/designs/<handle>/.vite/manifest.json` (dev server off).
- Previews switch template caching off for the request and send `Cache-Control: no-store`: `{% cache %}` keys don't know the design.

**Only page requests.** Console, CP and action requests keep `templates/`, because installs and updates write into `@templates` / `getSiteTemplatesPath()` there (`LibraryUpdater::applySection`, owned files, `PackageUpdatePlanner::resolveAbsolutePath`).

Activating a design (phase 4) must clear template caches: with caching on, a switch without clearing shows a mix of both designs (verified 2026-10-22).

## 4. Decisions (2026-10-22)

- Designs are installed, updated and **activated on the development site** and deployed; servers stay content-only (`CRAFT_ALLOW_ADMIN_CHANGES=false`), so on them the Appearance screen lists designs and preview links only.
- Package handle: `design-<handle>` (e.g. `design-canopy`).
- Customers get designs from the Library (Commerce24, signed, plan check, priced like packs). `.s7pkg` upload is for the team only: admin + Dev Mode, signature required.
- Designs don't count toward a plan's package limit. After a downgrade an active paid design keeps working without updates, but can't be activated again once switched away.
- Built output: servers build `web/designs/<handle>` at deploy, like the main frontend (`DESIGN=<handle> npx vite build --config vite.design.mjs`), so `web/designs/` stays out of git; the package still ships a build so a design works on the development site right after install. This also keeps a design's JS current with the Theme's.

## 4a. The package (phase 2, 2026-10-22)

`site7-studio/design/build <handle>` (`services/design/DesignBuilder`) builds `packages/design-<handle>/` from `designs/<handle>/` and `web/designs/<handle>/`, in a staging folder swapped in at the end (`ThemeBuilder::startStaging()` / `commitStaging()`, keeping version, name and price):

```
manifest.json   type "design", requires {themes: [<the Theme on this site>]}
design.json     the design's design.json + formatVersion, theme {handle, version},
                frontendHash (frontend/src JS), overrides {path: checksum of
                templates/<path> now, or null for a file only the design has}
README.md       the design's DESIGN.md
files/designs/<handle>/       templates, src, design.json, DESIGN.md
files/web/designs/<handle>/   the build
```

The build refuses: an invalid handle; a `design.json` with another handle; no `templates/`; no build, or a build older than `designs/<handle>/src` or `frontend/src`; a build missing entries the site's own manifest has; any file that could run as code or reconfigure the server (`.php` and friends anywhere in the name, `.htaccess`, `.user.ini`, `web.config`, shell scripts) or a symlink (`DesignBuilder::forbiddenFiles()`). It never runs npm. `version` in the design's `design.json` is only the first version; `library/publish` raises it from then on.

Distribution: `design` is a `LibraryDistribution::KINDS` type (marker `design.json`), its `design.json` goes into the catalog metadata as `library` (Theme version, overrides), it downloads right after the Theme, and `LibraryDistribution::versions($handle)` lists the published versions (`GET /marketplace/versions/{handle}`, `52` §5; console `site7-studio/design/versions <package>`).

A Canopy package is about 4.6 MB, mostly the bundled icon font.

## 4b. Install, update, version, remove (phase 3, 2026-10-22)

All of these run on the development site only (`allowAdminChanges`, `DesignInstaller::assertCanChangeFiles()`), then the result is deployed.

- **Install** (`DesignInstaller::install()`, through `PackageManagerService::installPackage()`; console `site7-studio/design/install <package>`, which downloads it first when it isn't in the Library): `files/designs/<h>` → `designs/<h>`, one `InstalledFileBaselineService` row per file with the package's checksum. A file of the same name already here with other content is the site's: kept and reported, never overwritten. `files/web/designs/<h>` replaces `web/designs/<h>` whole (written next to it, then swapped). Status `installed`.
- **Never `enabled`.** `enablePackage()` refuses designs: which design is live is the `activeDesign` setting, and a plan change disables enabled packages, which must never change a live site's look.
- **Update** (`LibraryUpdater` → `DesignUpdater::apply()`, order: right after the Theme): each design file through `LibraryUpdater::decide()` with the baseline row's checksum (it travels with the database; the previous Library copy is the fallback). Untouched here → the new version's; edited here → kept ("kept your version"); gone upstream → deleted if untouched, else kept. The build is replaced whole. Any failure puts `designs/<h>` and `web/designs/<h>` back from a copy taken first.
- **Any version** (`LibraryUpdater::changeVersion()`, console `site7-studio/design/version <package> <version>`): checks the version is published (`versions()`), then the same path with that version (`updatePackage()` now downloads `$update['to']`), so going back works with the same rule.
- **Remove** (`DesignInstaller::remove()`, through `uninstallPackage()` / `removePackage()`; console `site7-studio/design/remove <package>`): refused for the active design. Untouched files are deleted, edited ones kept and reported, the build deleted, baseline rows removed, status `available` (it stays in the Library).
- **Signed only.** `PackageImportService::checkSignature()` refuses any archive containing a `design` that isn't signed by a trusted key, on every path in (download, upload, local repository). Uploading one (`MarketplaceController::actionImportUpload()`) also needs an admin in Dev Mode.
- **A design that only restyles** has no `templates/` after install (archives drop empty folders): the loader recognises a design by `designs/<h>/design.json` and then only swaps the CSS/JS.

Verified 2026-10-22 on site7-qa (Theme 1.3.1, the stand-in): versions listed; installed from Commerce24 (signature `c24-local`, 3 + 35 files, 3 baseline rows); a preview link renders Canopy, 14 pages 200; remove kept an edited `DESIGN.md` and deleted the rest; reinstall kept it again; an unsigned design archive is refused. With 0.1.1 published (a changed `canopy.css` and `DESIGN.md`): `library/updates` lists it; the update changed `canopy.css` and the build and kept the edited `DESIGN.md` ("kept your version"); `design/version … 0.1.0` went back with the same result, and forward again; no backup folder left behind.

## 4c. Appearance screen (phase 4, 2026-10-22)

Site7 Studio → **Appearance** (`site7-studio/appearance`, `AppearanceController`, permission `manageDesigns`, template `appearance/index.twig`, model `DesignService::designs()`): one card per design: the default first, then designs in the Library (installed, or authored here in `designs/<handle>`), then designs the Commerce24 catalog offers. Each card shows the version (installed / latest), plan status, and warnings: built on another Theme version; base templates that changed since the design was built (`DesignService::changedBases()` over `design.json` `overrides`, the design's copy hides those changes); `designs/<handle>` missing.

Actions:
- **Preview**: `appearance/preview` redirects to a signed 24-hour preview link (works on read-only servers).
- **Activate** (immediate, `DesignService::activate()`): refused when admin changes are off, when `SITE7_DESIGN` overrides the setting, when the design isn't on disk, and for a paid design no longer in the plan unless it's already the active one. Saves `activeDesign` with `Settings::mergeWithStored()` and clears template caches (`TagDependency::invalidate(cache, 'template')` + element caches), then fires `DesignService::EVENT_AFTER_ACTIVATE_DESIGN`. `DesignService::registerProjectConfigListener()` clears the same caches when a deploy (`craft up`) applies a changed `activeDesign`.
- **Install / Update to … / Use version / Remove**: background jobs (`siteKitJobs->start()` with the console commands of §4b) with the live progress page, like Library updates. Install shows only when the design isn't on disk, so a design authored on this site is never overwritten.
- Read-only environments show the list and Preview, with "install and activate on your development site, then deploy".

Verified 2026-10-22 on rp-craft in headless Chromium (logged in with `users/impersonate`): the screen renders; Activate on Canopy saved `activeDesign: canopy` and the site switched to Canopy's build; Activate on Default switched back.

## 5. Important classes

`services/design/DesignLoader` (`register()`, `resolve()`, `validHandle()`, `previewUrl()`, `previewHandle()`), `services/design/DesignService` (`designs()`, `activate()`, `clearCaches()`, `changedBases()`), `controllers/AppearanceController`, `services/design/DesignInstaller` (`install()`, `remove()`, `replaceBuild()`), `services/design/DesignUpdater` (`apply()`), `LibraryUpdater::changeVersion()`, `services/design/DesignBuilder` (`build()`, `forbiddenFiles()`, `isAllowedFile()`, `overrides()`, `entries()`, `frontendHash()`), `models/packages/DesignPackage`, `models/Settings::$activeDesign`, `console/controllers/DesignController` (`preview-url`, `build`, `versions`), `LibraryDistribution::versions()`. Tests: `tests/unit/services/design/DesignLoaderTest`, `DesignBuilderTest`.

## 6. Known limitations

- A design's build contains the site's frontend JS as it was when the design was built; a Theme update to that JS reaches a design site only when the design is rebuilt (or built on the server, §4).
- A design's copy of a template hides later Theme or block updates to that file.

## 7. Planned phases

2. ~~Package type `design`~~ (done, §4a). Still open: the versions endpoint in the Laravel Commerce24 app.
3. ~~`DesignInstaller` / `DesignUpdater`, signature required~~ (done and verified, §4b).
4. ~~Appearance screen~~ (done, §4c).
5. `release.sh`, `build-packs.sh`, `deploy.sh`; live tests on site7-qa.
