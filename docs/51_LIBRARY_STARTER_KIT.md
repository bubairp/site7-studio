# 51. Library Starter Kit (format v2)

## 1. Purpose

A Library Starter Kit sets up a whole site on a fresh Craft install with Site7 Studio, from Library packages. It is the top Library layer:

**Theme** (`49`) → **Section packages** (blocks, `14` §15) → **Templates** (pages, `50`) → **Starter Kit**

The kit itself holds only what no page owns, plus the list of what to install. This is how a site is sold and set up. The Full Site Kit (`48`) is now an internal tool (Dev Mode only). The blueprint Starter Kit system (`32`) is separate and unchanged; Library kits are hidden from its catalog.

## 2. Package format (`packages/<name>-starter-kit/`)

| File | Content |
|---|---|
| `manifest.json` | `type: "starter-kit"`, `requires: {themes: [<theme>], templates: [<every page>]}`, `pricingType: "free"` |
| `starter-kit.json` | `formatVersion: 2`, `craftVersion`, `theme`, `templates` count, `pages` (URIs), `demoSections`, `pluginTables`, content counts (no build date, so an unchanged rebuild is identical, `53` §3) |
| `content/` | `SiteKitContent` format (`48` §10). Plugin tables (`KitBuilder::PLUGIN_TABLES`): menus (`simplerpmenu`, `simplerpmenu_items`) and the HTML sitemap (`sitemaps`). Visitor content as demo content: entries of the sections Guest Entries accepts submissions into (reviews, `ThemeBuilder::visitorSectionUids()`). `links.json` connects the reviews to their pages (`50` §4). |

For rp-craft the kit is 136 KB (8 menus / 62 items, 79 sitemap rows, 3 reviews), so it is committed. The Template packages it requires are build output (`50` §2).

## 3. Console

```
php craft site7-studio/starter-kit/build "RP Craft"        # on the dev site; rebuilds every Template first (--templates=0 to skip)
php craft site7-studio/starter-kit/validate rp-craft-starter-kit
php craft site7-studio/starter-kit/install rp-craft-starter-kit
```

`starter-kit/apply <handle>` is internal.

## 4. CP: Site7 Studio → Install

The **Library Starter Kits** pane lists the Library's v2 kits. **Check** runs `KitInstaller::validateKit()`:
- every required Template is in the Library;
- the Theme is installed, or the Theme's own fresh-site checks pass (`49` §4).
It also lists the `.env` keys to add.

Each kit row says whether it fits this site before Check: once a Theme is installed, a kit with that Theme adds its pages (pages already here are skipped, edits kept) and a kit with another Theme needs a fresh site; a site with its own structure and no Theme needs a fresh site too. A kit that doesn't fit has no Check. An installed kit (or Theme) can't be disabled or removed (`43` #33).

**Install** starts `starter-kit/install` as a background job (`SiteKitJobs`) and opens its live progress page. The job page links back to the Install screen.

The blueprint wizard below the pane only appears when blueprint kits exist. In the Library, a v2 kit's page points to the Install screen, and v1-only buttons ("Install Starter Kit", "Create Page from Template") are hidden for v2 packages.

**From Commerce24 (`52`).** The pane also lists Commerce24's Library kits that this site's Library doesn't have. Check and Install work the same; the install job first downloads and verifies every package the kit needs.

## 5. Install (`KitInstaller::installKit`)

1. **Theme**, if it isn't enabled yet: `ThemeInstaller::installTheme()`. That covers backup, code, Composer, plugins, structure, Singles, settings content and the frontend build (`49` §4).
2. **Kit**, in a new process (the Theme replaced `vendor/` and the plugin list): `starter-kit/apply` → `installPackage()` + `enablePackage()`.
   - **Preflight:** the same checks as a Template (`TemplateInstaller::preflight()`: Theme enabled, Library ID range).
   - **Cascade:** the existing `starter-kit` cascade installs and enables every required Template. Each Template installs its blocks and content (`50` §6).
   - **`installContent()`:** every Template must now be enabled, otherwise it stops and lists them. Then `missingStructure()` runs and the content is imported. Plugin tables are replaced, and links connect reviews to products and posts.
3. `clear-caches/all`.

## 6. Verified (2026-10-02)

Fresh DDEV Craft 5.10.8.1 with only Site7 Studio installed. Installed through the CP (Install → Check → Install), watched in a browser.

- **Time:** 100 s, job "Done".
- **Content:** 550 entries, 62 menu items, 91 packages enabled (Theme, 22 blocks, 68 Templates).
- **IDs:** no element at or above the Library ID range.
- **Pages:** all 68 return 200. 57 have exactly rp-craft's visible text apart from the site name. That now includes the sitemap and the blog/product comments.
- **The other 11** differ only in the order of their "related" lists. All 7 blog posts share one `postDate`, and the lists sort by it (`orderBy('postDate DESC')`), so MySQL may return tied rows in either order. The data is identical on both sites. A tie-breaker in the site's templates (`postDate DESC, id DESC`) would make them match.

## 7. Known limitations

- **Fresh sites only.** The Theme step needs a fresh install. A kit whose Theme is already installed only adds pages and content.
- **No update or uninstall of a kit.**
- **Users don't travel.** Reviews' authors become the first admin, and Guest Entries' author setting must be set on the new site (`49` §7).
- **The kit's packages come from this site's Library or from Commerce24** (`52`).
- **The `.env` keys** the Theme lists (API keys, Commerce24) must be added by hand. Values never travel.

## 8. Important classes

`services/starterkit/KitBuilder`, `KitInstaller` (extends `theme/ThemeInstaller`), `console/controllers/StarterKitController`, `controllers/InstallWizardController` (`actionCheckLibraryKit`, `actionInstallLibraryKit`), `templates/install-wizard/index.twig`, `services/sitekit/SiteKitJobs` (`start(..., $back)`). Tests: `tests/unit/services/starterkit/KitBuilderTest`.
