# Phase 4 — Frontend & Environment Capture

Status: **Implemented.** Per `WEBSITE-STARTER-KIT-SYSTEM.md` Phase 4 - whole-environment capture (not scoped to selected pages/Global Sets), populating the `dependencies.plugins`/`dependencies.npmPackages`/`dependencies.frontendTooling` schema slots Phase 1 reserved.

## New services

- `src/services/FrontendToolingScanner.php` - purely filesystem-based (there is no Craft resource/API surface for "what build system does this project use"), so it doesn't go through `CraftResourceScanner`/`CraftResourceRegistry`, which are for native Craft resources only.
  - `detect(): ?array` - finds a `package.json` in the project root or a short list of conventional subdirectories (`frontend/`, `assets/`, `theme/` - checked in that order, root first), then identifies the primary build system by checking for known config filenames in priority order (Vite → webpack → gulp → Tailwind), falling back to `'plain'` if none match. Tailwind v4 configures itself via CSS (`@theme`) rather than a `tailwind.config.*` file, so it's also detected via a `tailwindcss` entry in `package.json`'s dependencies when no config-file match won first.
  - `detectAt(string $basePath): ?array` - the actual detection logic, Craft-independent, extracted specifically so it's unit-testable against a real temp directory without a live Craft app. `detect()` is a one-line wrapper that finds the real project root (`dirname(Craft::$app->getPath()->getVendorPath())` - derived via the Craft API, not a project-specific bootstrap constant) and delegates.
  - `captureNpmDependencies(string $root): array` - `package.json`'s `dependencies`/`devDependencies`, flattened into `{name, version, dev}`.
- `src/services/ComposerDependencyScanner.php` - `captureComposerPluginDependencies(): array` returns `{handle, package, versionConstraint}` for every installed Craft plugin project-wide, excluding `site7-studio` itself (whatever performs an install already needs Site7 Studio running to do so - it's never itself a captured dependency). Cross-references `Craft::$app->getPlugins()->getComposerPluginInfo()` (which Composer package each installed plugin handle maps to) against the project's own root `composer.json` `require`/`require-dev` (for the actual version constraint declared there, not the plugin's own internal version number - these can differ, e.g. this project's `ether/seo` plugin declares its own version as `5.0.0-rc5` but the project's composer.json constrains it as `^v5.0.0-rc5`).

Both registered on the plugin service locator (`frontendToolingScanner`, `composerDependencyScanner`) and directly `new`-able, matching every other service added in this initiative.

## Wired into WebsiteImportService

Unlike Phases 2-3 (which only capture what a *selected* page/Global Set references), Phase 4's capture is whole-project by design - "what plugins/npm packages/build tooling does this site run" doesn't depend on which pages were selected. `importWebsite()` now always calls both scanners and populates:

```
manifest.dependencies.plugins        // every installed Craft plugin (handle, package, versionConstraint)
manifest.dependencies.npmPackages    // package.json dependencies + devDependencies
manifest.dependencies.frontendTooling // {system, configFiles[]}
```

**Bundling the actual config file contents**, per the phase's own instruction to "likely reuse the existing archive/exclude-list machinery... scoped to an allow-list of config paths": `WebsiteImportService::copyFrontendConfigFiles()` copies exactly the files `frontendTooling.configFiles` lists (never `node_modules/`, build output, or source) into the package's own `frontend/` subdirectory at capture time - ordinary files sitting alongside `template.twig`/`fields.yaml`/`manifest.json` from that point on. No changes were needed to `PackageArchiveHelper` (the zip/checksum machinery used whenever a package is later exported into a `.s7pkg`) - it already walks the whole package directory excluding OS/editor cruft, so these files ship automatically the moment they exist inside the package folder.

## Tests

`tests/unit/services/FrontendToolingScannerTest.php` (new) - against a real temp directory (same pattern as `ManifestReaderTest`): no `package.json` anywhere → `null`; a `package.json` + `vite.config.mjs` in a `frontend/` subdirectory is found and system-detected; the project root wins over a nested `frontend/` subdirectory when both have a `package.json`; the Tailwind-v4-via-dependency fallback; defaulting to `'plain'`; `captureNpmDependencies()` splitting regular vs. dev dependencies correctly, and returning `[]` with no `package.json`.

`ComposerDependencyScanner` has no dedicated unit test - every branch of its one method needs a live `Craft::$app->getPlugins()` and a real `composer.json`, which is exactly what the live DDEV verification below exercises instead (matching this repo's established convention of only unit-testing the Craft-independent pieces).

This host still has no PHPUnit/Codeception binary, so `FrontendToolingScannerTest`'s assertions were additionally hand-verified via a direct PHP script exercising the same temp-directory scenarios.

## Live verification

Against the real DDEV site - this project turned out to have real, non-trivial frontend tooling to verify against (not synthesized): a `frontend/` subdirectory (not the project root) containing a Vite 5 config with a `@tailwindcss/vite` plugin (Tailwind v4, CSS-configured), Sass, ESLint, and Stylelint, plus a root `composer.json` with 27 `require` entries.

1. **`FrontendToolingScanner::detect()`** correctly found `frontend/` (not the project root, which has no `package.json`), correctly identified `vite` as the system (via `vite.config.mjs`, ahead of the Tailwind-dependency fallback), and captured `vite.config.mjs`, `eslint.config.js`, `.eslintrc.js`, and `package.json`.
2. **`captureNpmDependencies()`** returned 20 real entries; spot-checked `swiper` (a regular dependency) marked `dev: false` and `vite`/`tailwindcss` (dev dependencies) marked `dev: true`.
3. **`ComposerDependencyScanner::captureComposerPluginDependencies()`** returned 22 entries (every installed plugin except `site7-studio` itself), matching the installed-plugin count exactly; spot-checked `remoteprogrammer/simple-rp-menu` → `^1.0.2` and the unusual `ether/seo` → `^v5.0.0-rc5` constraint, both matching the real `composer.json` verbatim.
4. **Full end-to-end** via `WebsiteImportService::importWebsite()` (Entry #8293 again): the resulting `manifest.json` carried all three captured dependency lists, and the package's own `frontend/` subdirectory contained physical copies of exactly the four config files - `vite.config.mjs`'s copy verified byte-for-byte identical to the real source file. Temporary Starter Kit + Template packages deleted immediately after (confirmed via `git status` on `packages/` showing no residue).

## Deferred to later phases

Snapshotting `node_modules`/build output (explicitly out of scope per the plan's own open question - config-only is the chosen, more portable option), any install-side application of the captured dependency lists (installing plugins via Composer, running `npm install`, applying frontend config to a fresh site) - that's Phase 5/6 territory, and this phase is capture-only like Phases 2-3 before it.
