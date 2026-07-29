# SITE7 Studio — Complete Website Starter Kit System

Status: **Draft for review — planning only, nothing in this document has been implemented.**

## Goal

Extend the existing "Import Existing Section / Page / Website" features into a full **Craft CMS Website Bootstrap Platform**: capture an entire existing Craft CMS project (schema, content, frontend tooling, plugins, dependencies) into a distributable `.s7pkg` Starter Kit, and reinstall that Starter Kit onto a fresh Craft CMS site through a setup wizard that provisions everything automatically. Craft CMS stays the single source of truth throughout — SITE7 Studio imports/synchronizes native Craft resources via the official Craft APIs, it never creates a shadow copy of them.

## Where the codebase actually is today

This section is the product of reading the real implementation, not the pitch — it's the baseline the phases below build from.

**Exists and works today:**
- Section/Entry Type field-layout capture → `fields.yaml`/`matrix.yaml`/`template.twig` (`MatrixEntryTypeImportService`, `CraftSectionImportService`)
- Page → Template capture, either from Site7-authored content or native Craft field values (`PageImportService`)
- A real "Import Existing Website" flow: multi-select Entries + Global Sets → Starter Kit package, with a working Select → Analyze → Preview → Save wizard (`WebsiteImportService`, `resource-import-wizard.js`, `ResourceImportController`)
- Starter Kit install for pages/templates (`StarterKitInstallationService`), riding on the existing package-install cascade
- A Shared Resource registry/resolver for fields and matrix fields referenced-not-duplicated across packages
- Field-level dependency classification (`ResourceClassifierService`), including plugin-provided-field detection (reporting only)

**Explicit, documented gaps (from the code's own docblocks) — this is most of the requested feature:**
- No asset volume, category/tag group, or Craft Section-settings capture — out of scope by design so far
- No real Navigation model (approximated via structure-entry nesting; one hardcoded plugin-classname check, nothing general)
- No real Platform Configuration model (`ResourceClassifierService` calls its own detection a "placeholder heuristic pending a full PlatformConfigService")
- `StarterKitInstallationService` doesn't even read `manifest->globals` yet — a package captured by the newer `WebsiteImportService` silently loses its globals on install today
- **Zero** existing infrastructure for: installing a Craft plugin programmatically, running `composer`/`npm` as a subprocess, capturing or applying frontend build tooling (Vite/Tailwind/SCSS), or diffing/applying arbitrary Project Config. None of this is stubbed — it doesn't exist anywhere in the plugin.
- `PackageManifest` has no schema fields for any of the above (plugins-as-installable-dependencies, npm/Composer package lists, build tooling, Project Config paths)

**Read this as:** the "Section/Page/Website content" half of the feature is a real extension of working code. The "plugins, frontend tooling, dependency installation, fresh-site provisioning" half is greenfield infrastructure with no existing scaffolding to lean on, and it's also the highest-risk half (subprocess execution, Project Config mutation, "worked on a fresh install" is much harder to guarantee than "worked on the dev site").

## Proposed phases

Each phase should ship independently testable, with a live E2E pass against the DDEV site before being called done (per this project's established practice — `php -l`/unit tests alone haven't caught the real bugs historically).

### Phase 1 — Manifest & dependency model foundations — **Implemented**, see `PHASE-1-STARTER-KIT-MANIFEST-SCHEMA.md`
Extend `PackageManifest` with the new schema surface needed by every later phase, with no capture/install logic yet: `dependencies.plugins[]` (handle, Composer package name, version constraint — distinct from today's reporting-only `pluginDependencies`), `dependencies.npmPackages[]`, `dependencies.frontendTooling` (build system identifier + config file list), `assetVolumes[]`, `categoryGroups[]`/`tagGroups[]`, `navigation[]`, `projectConfigPaths[]`. Also fix the known `globals` drop bug in `StarterKitInstallationService` while touching this area. Deliverable: schema + validation rules only, covered by round-trip read/write tests.

### Phase 2 — Native resource capture: volumes, categories/tags, section settings
Extend the import services to actually capture Asset Volumes, Category/Tag Groups, and Craft Section-level settings (site propagation, URL format, etc.) — the first three explicitly-deferred items. These are read-only captures into the Phase 1 schema; no install-side changes yet.

### Phase 3 — Navigation & Platform Configuration
Replace the structure-nesting approximation with a real Navigation capture (compatible with common nav plugins where present, degrading gracefully where absent), and build the real `PlatformConfigService` that `ResourceClassifierService` has been waiting on, replacing its keyword-heuristic placeholder.

### Phase 4 — Frontend & environment capture
Capture `composer.json`/`package.json` dependency lists, detect and record frontend build tooling (Vite/Tailwind/SCSS/plain JS) config files, and decide + implement how their actual file contents get bundled into a `.s7pkg` (likely reusing the existing archive/exclude-list machinery from the recent stabilization pass, scoped to an allow-list of config paths rather than a whole-project dump).

### Phase 5 — Project Builder / Dependency Analyzer / Blueprint Builder / Starter Kit Builder
The orchestration layer requested explicitly: a "capture everything" one-click flow sitting above the existing per-resource importers (today's Website import is still a manual multi-select), a Dependency Analyzer that produces an ordered install plan across all resource kinds from Phase 1-4 (plugins → schema → content → frontend, respecting the existing package `requires`/Shared-Resource graphs alongside the new plugin/npm graph), and a Blueprint Builder that turns that plan into the manifest's installation-order data. This is the first phase where "the whole project" becomes a single coherent object instead of several independent captures.

### Phase 6 — Install orchestration infrastructure (highest risk)
Build the actually-missing installer primitives: programmatic Craft plugin install/enable (Composer + `getPlugins()->installPlugin()`/`enablePlugin()`), subprocess execution for `composer install`/`npm install` with proper timeout/output handling from a web request context, and a Project Config apply/diff mechanism that goes beyond today's narrow rebuild/get calls. This phase needs explicit discussion on execution model (queue job vs. synchronous request, since installs can run long) and safety (dry-run/diff-preview before mutating a real site, given Craft's own strong warnings about Project Config drift).

### Phase 7 — Fresh-install setup wizard
The user-facing wizard on a fresh Craft install: select a Starter Kit → review the Blueprint's plan → execute via Phase 6 orchestration (plugins → resources → frontend config → dependency install → demo content) → land on a working site. Builds entirely on Phases 1-6; this is largely UI/flow work plus progress reporting for a necessarily long-running operation.

### Phase 8 — Sync/update & hardening
"Craft remains the single source of truth" implies this isn't one-shot: re-running an import against a site that already has the Starter Kit installed should synchronize/update rather than duplicate or blindly overwrite. This phase covers idempotent re-import, drift detection, and the safety guards (no destructive Project Config writes, no silent data loss) needed before this is production-safe — plus a full live E2E pass of capture → package → fresh-install → verify.

## Open questions worth resolving before Phase 1 starts
1. **Execution model for Phase 6/7** — synchronous CP request, a Craft queue job, or a console command the wizard shells out to? Long-running Composer/npm installs don't fit well inside a normal PHP-FPM request lifetime.
2. **Frontend tooling scope** — capture *config* only (Vite/Tailwind/SCSS config files + package.json) and let npm install pull the rest, or actually snapshot `node_modules`/build output too? Config-only is far more portable but assumes the target environment can run the build.
3. **Project Config strategy** — apply captured resources purely through Craft's element/service APIs (as today's importers already do), or ever touch `config/project/` YAML directly? The prompt says "using the official Craft CMS APIs while preserving full Project Config compatibility," which reads as API-only — worth confirming that's a hard constraint before Phase 6 design.
4. **Plugin installability** — some source-site plugins may be commercial/licensed and not installable unattended on a fresh target site. Needs a defined behavior (skip + report vs. hard-fail) rather than assuming every captured plugin can always be silently reinstalled.
