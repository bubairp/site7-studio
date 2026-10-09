# 59. Tailwind Safelist for Library Blocks

## 1. Problem

The Theme (`49`) ships its built frontend (`web/themes/front`), compiled on the author site, where every Library block is installed: it styles every block. Its Tailwind v4 entry, `frontend/src/css/app.css`, scans only the site's own templates (`@source '../../../templates/**/*.twig'`). Once a customer runs `npm run build`, the CSS keeps only the classes of the blocks installed at that moment. A block installed later shows partly unstyled. Measured on a customer site (2026-10-09): pricing-and-compare was missing 31 classes, contact 11, cta-banner 6. Nothing told the user to rebuild.

## 2. Design

One generated file, `site7-library.safelist`, next to the Tailwind entry. It holds one class per line, sorted, and only ever grows. The entry gets `@source './site7-library.safelist';`, so Tailwind scans the list like a template. All the logic lives in `src/services/theme/TailwindSafelist.php`.

| When | What | Where |
|---|---|---|
| Theme build (author site) | The staged copy of `frontend/` gets the classes of **every** Library Section package and the `@source` line. rp-craft's own `frontend/` is not changed: it doesn't need the list, since all blocks are installed there. | `ThemeBuilder` → `TailwindSafelist::merge("{$dir}/files", libraryClasses())` |
| Section package install | Its `template.twig` classes are merged in (the `@source` line is added if missing: Themes built before this). If the built CSS lacks some of its utilities, `getLastInstallWarnings()` gets "Run `npm run build` in frontend/ to apply the styles of <block>: …". It shows as the CP notice and is logged. | `PackageManagerService::installPackage()` → `afterBlockInstalled()` |
| Section package update | The same check on the live `_blocks` template, as a note in the update report. | `LibraryUpdater::applySection()` |
| Library Starter Kit / page pack | Its blocks are installed in a subprocess, so the same check runs afterwards in the parent and becomes a kit warning. | `KitInstaller::installKit()` → `missingByBlock()` / `rebuildMessage()` |
| Theme update | The safelist is **union-merged** (site list + new Theme list), never applied or kept by the three-way rule. The `@source` line is inserted the same way on both sides (`withSourceLine()`), so the patched `app.css` equals the new Theme's and counts as already applied. | `ThemeUpdater::applyFiles()` |
| Console | `site7-studio/frontend/check` lists each installed block's utilities missing from the built CSS. `site7-studio/frontend/safelist` back-fills the list (installed blocks + this Library) on a site set up before this existed. | `FrontendController` |

npm is never run from the plugin for a block install; the user rebuilds.

**The built-CSS check** reads the Vite manifest under `ThemeBuilder::builtFrontendPath()`, takes every CSS file it lists, and collects class selectors with CSS escapes undone. A class counts as missing when it's a utility-looking name from a `class` attribute (variant `:`, arbitrary `[`, or a common utility stem) that isn't a selector. The same heuristic is used by the coordinator's `fe-library-coverage.py`.

**Extraction** (`extractClasses()`) reads:
- `class="…"` / `class='…'` attributes, with `{% %}` and `{# #}` treated as whitespace and a `{{ }}` spoiling the name it touches;
- quoted strings inside Twig tags (`{% set c = 'p-4 md:p-8' %}`, `{{ x ? 'text-white' : '' }}`).

Extra non-class tokens are harmless: Tailwind just doesn't generate anything for them.

## 3. Not an installed file

The safelist belongs to no single package and is merged by many, so it's never a `site7_installed_files` baseline. Update/conflict checks (`19`), Repair and the uninstall snapshot (`58`) don't see it. It's ordinary site data and survives a plugin uninstall.

## 4. Convention for block authors

Write full class names. `lg:grid-cols-{{ n }}` can't be read from a template, by this code or by Tailwind. Use a lookup instead: `{% set cols = {2: 'lg:grid-cols-2', 3: 'lg:grid-cols-3'} %}` puts the literals in the template, so they're found. Blogs, case-studies, products, teams, testimonials and universal-card currently concatenate `lg:grid-cols-`; they only work while those classes appear literally elsewhere.

## 5. Verified (2026-10-09, site7-uninstall-test)

The site ran on CSS rebuilt by the customer (`npm ci && vite build`).
- **Before the fix:** installing the Pricing page pack (pricing-and-compare, accordion, services) and the cta-banner and contact blocks reported "Package installed successfully". Rebuilt CSS was missing 31 / 6 / 11 / 2 classes.
- **After the fix, same installs:**
  - the kit warning and the block notices named the blocks and counts;
  - `app.css` gained exactly one `@source` line;
  - the safelist was created.
- **With the Theme-build list:** the same `merge()` ThemeBuilder runs, on a scratch copy of rp-craft's `frontend/src/css`, gave 806 classes. After `npm run build`:
  - `fe-library-coverage.py` showed 0 real misses for all 27 Library Section packages, installed or not (only the `lg:grid-cols-` concatenation, also "missing" from the shipped CSS);
  - `frontend/check` reported every block styled;
  - `/price` rendered with no class lost compared to the shipped CSS.
- **Theme update:** simulated through `ThemeUpdater::applyFiles()` on a scratch root, the safelist was merged (806 to 807, no site class lost) and `app.css` was unchanged.
- **Uninstall/reinstall and package actions:** plugin uninstall/reinstall restored everything identically, and both files' checksums were unchanged. Reinstall/Repair of the blocks gave no warnings and no state change.
- Unit: `tests/unit/services/theme/TailwindSafelistTest.php`.

## 6. Gaps / future work

- Not run: a real Theme build/publish with the safelist (it would change the author Library; do it with the next Theme publish), a real Library update of a block, and the CP screens (the notice text was checked, not rendered).
- A theme without Tailwind v4 `@source` support or without `frontend/` is left alone (`merge()` returns null).
- The utility heuristic can miss custom-named utilities, or flag a class the theme's CSS doesn't need. `frontend/check` is advisory.
