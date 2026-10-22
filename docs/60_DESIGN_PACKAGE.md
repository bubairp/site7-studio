# 60. Design packages (switchable designs)

## 1. Purpose

A site keeps its content and CMS structure and can switch its whole look between installed **designs**, the way WordPress switches themes. A design is **frontend only**: templates, CSS/JS, fonts and images. It reads the same fields as every other design and never adds fields, sections or entry types; anything new in the CMS goes into the Theme (`49`) for every design.

The **default design** is the site's own `templates/` and `frontend/` (on rp-craft: "Editorial", handle `default`). It is never a package and is always there to fall back to.

Status (2026-10-22): phase 1 of the plan is built (loader, setting, preview links). Packaging, distribution, the Appearance screen and release steps follow (§7).

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
- Built output: under discussion whether servers build `web/designs/<handle>` at deploy (like the main frontend) or customer repositories track it.

## 5. Important classes

`services/design/DesignLoader` (`register()`, `resolve()`, `validHandle()`, `previewUrl()`, `previewHandle()`), `models/Settings::$activeDesign`, `console/controllers/DesignController` (`preview-url`). Tests: `tests/unit/services/design/DesignLoaderTest`.

## 6. Known limitations

- A design's build contains the site's frontend JS as it was when the design was built; a Theme update to that JS reaches a design site only when the design is rebuilt (or built on the server, §4).
- A design's copy of a template hides later Theme or block updates to that file.

## 7. Planned phases

2. Package type `design`: `DesignBuilder`, `LibraryDistribution` KINDS / catalog metadata, a versions endpoint on Commerce24.
3. `DesignInstaller` / `DesignUpdater` (three-way file rule, version change, remove), signature required for design archives.
4. Appearance screen (list, preview, activate with cache clearing).
5. `release.sh`, `build-packs.sh`, `deploy.sh`; live tests on site7-qa.
