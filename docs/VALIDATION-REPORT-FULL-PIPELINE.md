# Full-Pipeline End-to-End Validation Report

**Status: COMPLETE.**

## Executive Summary

The pipeline was run for real: a live capture from `rp-craft` (35 sections,
109 entry types, 167 fields, 885 entries, 124 assets, 21 plugins), a freshly
provisioned Craft 5.10 + Site7 Studio-only DDEV site (`rp-craft-fresh`), and
an attempt to move real content from one to the other through every
mechanism the codebase actually offers.

**Verdict: not ready for Marketplace work.** The validation did not reach a
working recreated site. Two independent, hard blockers were found, each
sufecient on its own to fail Step 4/5:

1. **The production "Generate Starter Kit" path cannot capture this real
   site's content at all** (0 of 885 entries qualify — see Step 2), and
2. **Neither of the two "capture the whole site" services produces a
   `blueprint.json`**, which is the only file format the Fresh Install
   Wizard/`InstallController` can install — only a dead, uncalled code path
   (`StarterKitBuilder`) produces one (see Step 2/4).

A third, independent, and equally serious bug was found by the mere act of
provisioning a fresh site and installing the plugin on it — something this
plugin has apparently never had exercised on a truly blank database before
today (see Step 3): **`plugin/install` silently records all of Site7
Studio's migrations as applied without running their DDL**, leaving every
`site7_*` table missing. A fourth, independent bug (schema drift in
`PackageRepository::save()`) then blocked even the fallback Marketplace
install path once the migration bug was manually worked around.

None of these four are related to each other by a shared root cause except
in the broadest sense (undertested fresh-environment paths); each has its
own fix recommendation in Step 6.

Source site: `rp-craft` (DDEV, Craft CMS 5.0, PHP 8.2) — a real production-quality
site: 35 sections, 109 entry types, 167 fields, 2 category groups, 1 tag group,
0 global sets, 1 asset volume (124 assets), 21 installed plugins, 885 entries.

## Step 1 — Build the Complete Library

### Baseline inventory (rp-craft)

Captured via a throwaway console command (`ScratchDebugController::actionInventory`,
deleted after use per project convention):

| Resource | Count |
|---|---|
| Sections | 35 |
| Entry Types | 109 |
| Fields | 167 |
| Category Groups | 2 |
| Tag Groups | 1 |
| Global Sets | 0 |
| Asset Volumes | 1 |
| Assets | 124 |
| Installed Plugins | 21 |
| Entries | 885 |

### Finding: "Import Existing Website" is unreliable for multi-page capture

**Category: Installation issue (project-config concurrency)**

Driving `WebsiteImportService::importWebsite()` — the real backing service for
the CP's "Import Existing Website" flow — with more than one entry ID in a
single request reproduces two distinct, non-deterministic failures on repeat
runs of the *identical* input:

1. `A lock could not be acquired to modify the project config.`
   (`craft\services\ProjectConfig::_acquireLock()`, raised via
   `PackageManagerService::invalidateCraftCaches()` → `ProjectConfig::rebuild()`,
   called from `PageImportService::importFromEntry()` at
   `src/services/import/PageImportService.php:190`.)
2. `The loaded project config is out-of-date.`

**Root cause:** `WebsiteImportService::importWebsite()` loops over every
selected entry and calls `PageImportService::importFromEntry()` per entry
(`src/services/import/WebsiteImportService.php:108`). Each of those calls
independently installs its captured page package and immediately triggers a
full `Craft::$app->getProjectConfig()->rebuild()` inside
`PackageManagerService::installPackage()` →
`invalidateCraftCaches()` (`src/services/PackageManagerService.php:290,399`).
Doing this repeatedly, in-process, in a tight loop, races Craft's own
project-config mutation lock and its in-memory "loaded config" staleness
check. A single-entry capture reliably succeeds; capturing more than one page
in the same website-import call intermittently fails — reproduced twice,
succeeded once on retry with identical input, confirming it's a race rather
than a hard block.

This is exactly the class of problem Phase 7's own docblock
(`InstallationOrchestratorService`) already names and works around — *for the
installer* — by spawning one subprocess per stage specifically "to work
around Craft's per-process Composer-plugin-manifest and Project Config
propagation bugs discovered during Phase 6 testing." The same subprocess
isolation was never applied to the **capture/import** side
(`WebsiteImportService`, `PageImportService`), which still runs its per-page
install-and-rebuild loop in a single process. **Proposed fix:** either (a)
defer `invalidateCraftCaches()`/`ProjectConfig::rebuild()` to run once after
the whole batch of pages is captured, not once per page, or (b) reuse the
existing per-stage-subprocess pattern from `InstallationOrchestratorService`
for multi-page website capture too.

### Result of the full 30-entry capture (1 representative entry per section)

After the concurrency issue above, a clean run completed successfully:
handle `rp-craft-full-site-capture-4`, 30 entries submitted, **18 captured, 12
skipped**.

**Skip reasons (11 of 12):** "Nothing could be captured from this resource -
it has no supported fields or content." — these are `single`-type entries
whose only field is something `TemplateGeneratorService`/`PageImportService`
doesn't recognize as capturable Site7 content (e.g. Sitemap, Page
Not Found/Maintenance/error, Additional CSS & JS, LLMs Text, and a few
`Test`/`Support`/`White`/`Services 01` entries backed by native
Craft/CKEditor fields with no Site7 Matrix block content). **This is expected
project scope, not a bug** — these pages have no Site7-authored content to
capture in the first place.

**Skip reason (1 of 12) — genuine bug:** one entry failed with `A Template
name is required.` because the entry's `title` is blank. **Category:
Capture issue.** `PageImportService`/`TemplateGeneratorService` derive the
generated Template's name directly from `$entry->title` with no fallback for
untitled entries (e.g. Structure/Channel entries that intentionally have no
title, common for pure-content-block entries) — worth a fallback to
slug/section-handle/id.

### Finding: Category and Tag field *values* are never captured

**Category: Capture issue**

Across the successful captures, every entry with a populated `craft\fields\Categories`
or `craft\fields\Tags` field logged: *"references Category/Tag field 'X' -
categories/tags are not imported, links will be empty on install."* Cross-
referencing `WebsiteImportService`'s own docblock: it captures Category
Group/Tag Group **settings** (so the group and its field structure exists on
the target), but never the actual assigned category/tag terms on an entry,
and never re-links a captured entry's Categories/Tags field values after
install. A recreated site will have the right groups but every
category/tag relationship silently emptied. Confirmed on real content:
`blogCategories`, `portfolioCategories`, `tags` all hit this on rp-craft.
**Proposed fix:** extend `CraftResourceRegistry`'s dependency graph (already
used for Volume/Category/Tag Group *setting* capture) to also serialize the
actual selected term UIDs per entry, and have the installer re-select them
after the target's Category/Tag Groups + terms exist.

### Finding: Real navigation capture works correctly

**Not a gap — confirmed working.** The "Footer" entry's four navigation
fields (`footerMenu`, `footerMenuCol1/2/3`, backed by the `simple-rp-menu`
plugin) were captured with a note confirming *"captured the real menu
structure, not just the Structure-nesting approximation"* — i.e.
`NavigationScanner`'s real-nav-plugin detection path (described in
`WebsiteImportService`'s docblock) fired correctly against real production
navigation data, not just a synthetic test case.

### Step 1 scope note

Testing used one representative entry per section (30 of 35 sections
produced a usable entry; 885 entries exist site-wide) rather than every
entry, since capturing schema-level resources (Sections, Entry Types,
Fields, Matrix Fields, Category/Tag Groups, Navigation, Plugins,
composer/npm dependencies) only needs to happen once per distinct
type/structure — re-running the same import against the other 855 entries
would exercise the same code paths without new signal, given the time cost
already observed (a 30-entry run took several minutes end-to-end, see
performance finding below).

### Finding: Multi-page import performance

**Category: Installation issue (performance/scalability)**

The 30-entry run took multiple minutes end-to-end (single entries typically
resolve in well under a minute). Given each entry triggers its own full
`ProjectConfig::rebuild()` (see the concurrency finding above), the fix for
that issue would very likely also fix this one — rebuilding project config
once per batch instead of once per page removes both the race and the
N-times redundant full-schema rebuild cost. On a real client site with
several hundred content-bearing pages, importing "the whole website" through
the current per-entry-rebuild design would not practically complete in one
request.

### Coverage against the requested capture-target list

| Target | Status |
|---|---|
| Sections | Captured (via referenced pages' section handle) |
| Entry Types | Captured |
| Fields | Captured |
| Matrix Fields | Captured |
| Categories (groups) | Captured (settings only — see finding above for values) |
| Tag Groups | Captured (settings only — see finding above for values) |
| Globals | Supported by `importWebsite()` signature; none exist on rp-craft (0 Global Sets) — untested live, code path present |
| Asset Volumes | Captured (settings only) |
| Assets | **Not captured** — no code path serializes actual asset files, only Volume config (confirmed absent, matches recon) |
| Navigation | Captured (real nav-plugin path confirmed working, see finding above) |
| Plugin dependencies | Captured (whole-environment snapshot) |
| Platform configuration | Captured |
| Frontend tooling (Vite/Tailwind/npm) | Captured (config files copied into package) |
| composer.json | Captured |
| Pages | Captured (18/30 with real content; 11 legitimately empty singles; 1 bug — blank-title entry) |
| Templates | Captured (1:1 with each captured page) |
| Components/Patterns | Not separately exercised this pass — `ComponentRegistry`/`PatternInsertionService` exist per recon but weren't driven by this capture flow; **not evaluated** (scope gap in this validation run, not a confirmed product gap) |
| Starter Kits (as capture target) | N/A — Starter Kits are an output artifact, not something captured from a live site |
| Twig Layouts/Partials/Macros | **Not captured** — no dedicated service (confirmed absent, matches recon) |
| SCSS | **Not captured** — only Tailwind/Vite/npm config detected, no SCSS-specific handling (confirmed absent, matches recon) |
| Demo content | **Not captured** as a distinct concept — captured pages/entries serve this role but there's no "demo content" flag/service distinct from normal page capture |

## Step 2 — Generate a Production Starter Kit

**Architectural note carried over from reconnaissance:** the actually-wired
generation path is `StarterKitGeneratorService::generateFromEntries()`, not
the `ProjectBuilder`/`BlueprintBuilder`/`StarterKitBuilder` pipeline described
in the Phase 5 doc — that pipeline has zero callers anywhere in the codebase.
This validation exercises the real path.

**Finding — the real generator's own docblock scopes it down sharply:**
*(see `src/services/StarterKitGeneratorService.php:14-16`)*: *"Phase 10
scope: Pages + Templates only - Navigation, Globals, Categories, Assets, and
SEO are deferred to later increments."* This means the actual
production "Generate Starter Kit" mechanism cannot, by its own documented
design, carry Navigation, Globals, Categories, Assets, or SEO — a materially
smaller scope than `WebsiteImportService`'s capture (Step 1), which *does*
reach Navigation and Category/Tag Group settings. **Category: Blueprint/
Packaging issue — the two "capture the whole site" mechanisms
(`WebsiteImportService` for Library import vs. `StarterKitGeneratorService`
for Starter Kit generation) have diverged capability, and a developer
generating a Starter Kit the normal way gets a smaller subset of the site
than what the Library import (Step 1) is actually able to capture.**

### Finding: no Starter Kit produced by the real generator can ever be installed

**Category: Blueprint/Installation issue — critical, blocking.**

Confirmed directly in code (`src/services/installation/StarterKitCatalogService.php`):
`listAvailable()` lists any package with a `manifest.json` (which
`StarterKitGeneratorService` does write), but `getBlueprint($handle)` — the
thing `InstallController`/`InstallWizardController` actually need to plan or
install anything — reads `blueprint.json`, which `StarterKitGeneratorService`
never writes. The catalog service already has a hardcoded message for this
exact situation (`describe()`, line ~110): *"No blueprint.json found - this
package was not built by the Starter Kit pipeline and cannot be installed by
this wizard."* Only `BlueprintBuilder` (part of the dead Phase 5
`ProjectBuilder`/`StarterKitBuilder` pipeline identified in reconnaissance,
which has zero callers anywhere in the codebase) ever produces a
`blueprint.json`. **In its current state, there is no way to generate a
Starter Kit through the actual UI/API that the Fresh Install Wizard can
subsequently install.** This is true regardless of source-site content —
it would be true even for a site built entirely with Site7 Studio's own
authoring tools.

### Finding: the real generator rejects 100% of this site's actual content

**Category: Capture issue — critical, blocking for this validation's premise.**

`TemplateGeneratorService::generateFromEntry()` (called by
`StarterKitGeneratorService::generateFromEntries()`) requires the entry's
`site7Components` Matrix field to contain at least one block, and throws
otherwise (`src/services/TemplateGeneratorService.php:27-42`). A direct
scan of all 885 entries on rp-craft
(`getFieldValue('site7Components')->status(null)->count()`) found **zero**
with any content in that field — confirmed by then running the actual
generator against the same 30 representative entries used in Step 1, which
failed outright: *"None of the selected pages have Site7 content, so no
Templates could be captured."* **The production "Save Current Site as
Starter Kit" mechanism only works for pages that were authored through
Site7 Studio's own visual builder; it cannot capture a single page from a
site built the traditional Craft way** (fields directly on entry types,
CKEditor bodies, etc.) — which describes the overwhelming majority of real
Craft sites, including this one. Combined with the finding above, the two
problems compound: even if this weren't true, the resulting package still
couldn't be installed by the Wizard.

## Step 3 — Provision a Brand-New Craft Site

Provisioned cleanly: `ddev config` (craftcms, PHP 8.3, MySQL 8.0,
apache-fpm) → `ddev composer create-project craftcms/craft` (installed
craftcms/cms 5.10.12) → `craft install --interactive=0` → `composer require
site7/studio` as a local path repo (symlinked, matching rp-craft's own
convention) → `ddev craft plugin/install site7-studio`. Nothing else
installed. This step itself succeeded, but installing the plugin surfaced
the critical finding below.

### Finding: `plugin/install` records all migrations as applied without running them

**Category: Craft CMS/Installation issue — critical, blocking, and directly
explains a previously-unexplained recurring problem from earlier this
session.**

Immediately after `ddev craft plugin/install site7-studio` reported success
(and `migrate/up --plugin=site7-studio` separately reported "No new
migrations found. Your system is up-to-date."), the plugin's own migration
history table showed all 9 entries (the base `Install` migration plus 8
numbered migrations) recorded as applied, all at the identical timestamp.
Yet `SHOW TABLES LIKE '%site7%'` on the actual database returned **zero
rows** — none of the plugin's tables existed at all, confirmed by then
trying to use the plugin (`MarketplaceService::installFromRepository()`
failed with `Table 'db.site7_shared_resources' doesn't exist`).

**Reproduced and root-caused:** deleting the 9
`migrations` table rows for `track='plugin:site7-studio'` and re-running
`ddev exec php craft migrate/up --plugin=site7-studio` then genuinely
executed the `CREATE TABLE` statements (visible in the command's own DDL
log output) and every `site7_*` table appeared correctly afterward. This
confirms the migrations themselves are correct and idempotent — the bug is
specifically in whatever `plugin/install` does differently from a plain
`migrate/up` on a truly fresh database, causing it to mark history without
applying schema.

This is almost certainly the same underlying issue this session already
hit once before, on `rp-craft` itself: `/admin/site7-studio/update` failed
with `Table 'db.rp_site7_installed_starter_kits' doesn't exist` and was
"fixed" at the time by manually running `migrate/up --plugin=site7-studio`
— without understanding why the table was missing on an already-installed
plugin. Today's fresh-install reproduction makes clear this isn't a one-off
data glitch: **it is the plugin's normal, repeatable behavior on first
install**, and it would silently break Site7 Studio for every single new
user who installs it on a new Craft site, immediately, with no obvious
error until they try to use a feature that touches a missing table.
**Proposed fix:** add a post-install verification step (assert the
migration history's row count for the plugin matches
`$plugin->getMigrator()->getNewMigrations()` actually being empty AND spot-
check that a known table exists) that fails loudly rather than silently, and
investigate why Craft's plugin-install path diverges from `migrate/up`'s
behavior for this plugin specifically — most likely something in
`Site7Studio`'s own `install()`/`afterInstall` handling short-circuits
migration execution while still letting Craft's plugin manager write
history rows.

## Step 4 — Build the Website Using the Fresh Install Wizard

**Blocked before it could start**, by the Step 2 findings: there is no
Starter Kit package with a `blueprint.json` to select in the wizard at all
— `StarterKitCatalogService::listAvailable()` would show the generated
package (it has `manifest.json`) but mark it "not installable" per its own
built-in check, and `actionValidate()`/`actionRun()` both hard-fail
immediately on a missing blueprint before any planning or execution logic
runs.

**Fallback attempted:** rather than stop entirely, the Step 1
`WebsiteImportService`-captured package (`rp-craft-full-site-capture-4`,
which does have real content — 18 pages) was exported via the existing
`PackageExportService`/`.s7pkg` mechanism, copied into
`rp-craft-fresh`'s Local Repository, and installed via the Marketplace's
`installFromRepository()` — the one other install mechanism in the
codebase, independent of the Wizard/blueprint pipeline. After working
around the Step 3 migration bug (by manually running `migrate/up`), this
also failed:

### Finding: `PackageRepository::save()` is dead/stale code still wired into the live install path

**Category: Packaging/Installation issue — critical, blocking.**

`site7\studio\services\PackageManagerService::discoverPackages()` (called
during any package import, including the Marketplace install fallback)
calls `PackageRepository::save()`
(`src/repositories/PackageRepository.php:31-49`), which sets
`$record->category` and `$record->tags` on a `PackageRecord`. Neither
column exists on the `site7_packages` table (confirmed via `DESCRIBE
site7_packages` — the real columns are `id, uid, name, handle, type,
version, status, authoringStatus, entitlementRemovableOn, creatorId,
description, author, requiredCraftVersion, minimumStudioVersion,
dateCreated, dateUpdated`). `category` is a real column, but on a
*different* table (`site7_components`, per the migration file) — this
looks like a copy/paste or refactor artifact. `PackageRepository`'s own
docblock comment gives it away: *"This is a simplified save logic...
would be handled by a more advanced PackageService in subsequent
milestones."* This is a third instance of the same pattern found twice
already in Step 2 (an old/simplified implementation left in place,
apparently superseded in intent by `PackageManagerService`/`PackageDiscovery`/
`PackageReader`, but never removed and — unlike the Phase 5 pipeline — still
actually reachable from a live code path). **On this rp-craft-fresh
install, this crashes any package import that goes through
`discoverPackages()` on a fresh site.** **Proposed fix:** determine whether
`PackageRepository::save()` is still meant to be called at all; if not,
remove it (matching how the Phase 5 pipeline should probably also be
removed once reviewed); if so, fix the property mismatch against the real
schema.

**As a result, Step 4 could not be completed via any mechanism currently in
the codebase** — not the intended Wizard path (no installable kit exists),
and not the Marketplace fallback (crashes on the schema-drift bug above).

## Step 5 — Full Verification

**Not reached.** No install ever completed on `rp-craft-fresh` to compare
against the `rp-craft` baseline. The Step 1 inventory baseline
(35 sections / 109 entry types / 167 fields / 2 category groups / 1 tag
group / 0 global sets / 1 asset volume, 124 assets / 21 plugins / 885
entries) stands ready for a future comparison once Step 4's blockers are
resolved — re-run the same `Craft::$app->getEntries()->getAllSections()`
etc. inventory used in Step 1 against the target site.

## Step 6 — Gap Analysis Summary

All findings, consolidated with category and severity:

| # | Finding | Category | Severity |
|---|---|---|---|
| 1 | `StarterKitGeneratorService` output has no `blueprint.json`; only the dead `StarterKitBuilder` pipeline produces one | Blueprint/Installation | **Critical — blocking** |
| 2 | `TemplateGeneratorService::generateFromEntry()` requires `site7Components` Matrix content; 0/885 real entries qualify | Capture | **Critical — blocking for real sites** |
| 3 | `plugin/install` on a fresh DB records migrations as applied without running their DDL | Craft CMS/Installation | **Critical — blocking, affects every new install** |
| 4 | `PackageRepository::save()` sets non-existent `category`/`tags` columns on `PackageRecord`, crashing `discoverPackages()` | Packaging/Installation | **Critical — blocking for Marketplace install path** |
| 5 | `WebsiteImportService` capturing >1 page in one process intermittently hits project-config lock/staleness errors | Installation (concurrency) | High |
| 6 | Same design implies poor performance at real scale (minutes for 30 pages, one full `ProjectConfig::rebuild()` per page) | Installation (performance) | High |
| 7 | Category/Tag field *values* are never captured or re-linked, only group settings | Capture | Medium |
| 8 | Entries with a blank title fail capture with an unhelpful error (`A Template name is required.`) | Capture | Low |
| 9 | Assets (actual files), SCSS, Twig Layouts/Partials/Macros as capture targets, and "Demo content" as a distinct concept have no capture path at all | Capture | Medium (scope gap, not a defect) |
| 10 | Components/Patterns capture (`ComponentRegistry`/`PatternInsertionService`) not exercised this run | Capture | Unknown — needs its own pass |
| 11 | No console command exists for the Marketplace install-from-repository flow (CP-only) | Tooling | Low |

### Recommendation before Marketplace work begins

Findings 1–4 are each independently blocking and should be fixed first, in
roughly this order (each unblocks the next validation attempt):
Finding 3 (plugin install) → Finding 4 (package discovery) → Finding 1
(wire a real generator to `blueprint.json`, or decide `StarterKitBuilder`
should be the one true path and delete `StarterKitGeneratorService`) →
Finding 2 (either broaden capture to native Craft content, or explicitly
scope Site7 Studio to "sites authored with Site7 Studio" and document that
as a hard prerequisite, since that changes the product's pitch materially).
Once those are fixed, this same validation should be re-run in full
(including Step 5's comparison, not attempted this pass) before any
Marketplace work begins — the premise of this validation (prove the
pipeline works end-to-end on a real site) was not met, and Marketplace
distribution would only amplify these gaps to every installer of a
published kit.

## Post-report fix pass (2026-07-30, same day)

Following this report, the critical/high findings were fixed and each was
verified live (console + browser) rather than just reviewed as a diff:

1. **`Install.php` migration stub** (Finding 3) — rewritten to replay every
   real migration's own `safeUp()` in order. Verified by wiping
   `rp-craft-fresh`'s database and reinstalling from scratch: all 12
   `site7_*` tables now appear correctly with no manual `migrate/up` needed.
2. **`PackageRepository::save()` schema drift** (Finding 4) — root cause
   fully identified: `category`/`tags` columns exist on rp-craft's
   production `site7_packages` table only because they were added by hand
   at some point, with no migration ever written for them. Added
   `m260730_123644_add_package_category_tags`.
3. **Dead `StarterKitBuilder` pipeline** (Finding 1) — wired into the real
   `ResourceImportController::actionImportWebsite()` endpoint. Verified live
   in the browser: a kit captured before this fix still shows "No
   blueprint.json found" in the Install Wizard; a kit captured after it does
   not.
4. **Project-config concurrency/performance** (Findings 5-6) — added
   `PackageManagerService::beginBatch()`/`endBatch()` so
   `WebsiteImportService::importWebsite()` triggers one project-config
   rebuild per capture instead of one per page.
5. **Stale `Craft::$app->getInfo()` cache** — a second, related bug found
   while fixing #4: `ProjectConfig::rebuild()`'s own staleness check
   compares against a `configVersion` cached once per process, which goes
   stale after many in-process writes even inside a single batch. Fixed by
   force-refreshing it (`getIsInstalled(true)`) before every rebuild.
6. **`SetupController`'s Matrix field creation** — new finding, only visible
   once a truly fresh site reached the wizard: Craft 5 requires a Matrix
   field to have at least one Entry Type before it can be saved at all
   (Craft 4 allowed zero). The wizard created one with none. Fixed with a
   minimal placeholder Entry Type, matching how real packages later add
   their own via `PackageManagerService::linkToMatrix()`.
7. **`site7_install_sessions.data` and three sibling columns too small** —
   new finding: these store a kit's full `blueprint.json`/report as JSON in
   a `text` column (65,535-byte MySQL limit); a real blueprint from just 4
   captured pages was 339,865 bytes. Added
   `m260730_130418_widen_install_session_data` (→ `mediumtext`, 16MB).
8. **Queue never auto-runs during the wizard's AJAX-polling flow** — new
   finding, not fixed: `InstallWizardController::actionExecute()` pushes an
   `InstallStarterKitJob` onto Craft's queue, but nothing triggers it while
   the wizard stays on one page polling `actionProgress()` — Craft's normal
   auto-run-queue mechanism appears to need a fresh full page load to fire.
   A real (non-dry-run) install session sat at `status=queued` indefinitely
   until `ddev craft queue/run` was triggered manually. Needs its own
   investigation into why the CP's usual queue-trigger JS isn't firing
   here — out of scope to fix blind in this pass.
9. **Local/private plugin dependencies have no capture or transport
   mechanism** (new, most fundamental finding) — `remoteprogrammer/ai-chat`,
   `remoteprogrammer/htmlsitemap`, and `remoteprogrammer/payment-gateway`
   are Composer `path` repositories: custom plugins whose PHP source lives
   only inside rp-craft's own `plugins/` directory, exactly like Site7
   Studio itself. No Starter Kit can ever `composer require` these on a
   different Craft installation, because there is nothing for Composer to
   fetch — only physically transplanting the plugin's source code
   alongside the kit would make it portable, which this plugin doesn't do.
   Confirmed live: a real (non-dry-run, real-network) installation attempt
   failed with an opaque, deeply-nested Composer resolution error for
   exactly these three packages. **Fixed the failure mode, not the
   capability**: `ComposerDependencyScanner` now detects `path`-repository
   packages by reading each one's own `composer.json` for the package name
   it provides, and `InstallationValidator` turns that into a hard,
   Step-2-blocking error with a clear, specific message - instead of the
   installation silently reaching deep into a real `composer update` before
   failing incomprehensibly. Verified live: Step 2 (Environment Validation)
   now correctly disables the "Continue" button and lists the exact reason
   for each of the three packages, before any Composer command runs.
   Actually shipping these plugins in a Starter Kit remains unsolved and
   would need a product decision (bundle plugin source into the `.s7pkg`,
   or require the target to have equivalent local plugins pre-installed).

### Files changed this pass (all uncommitted, pending review)

- `src/migrations/Install.php` (rewritten)
- `src/migrations/m260730_123644_add_package_category_tags.php` (new)
- `src/migrations/m260730_130418_widen_install_session_data.php` (new)
- `src/controllers/ResourceImportController.php`
- `src/controllers/SetupController.php`
- `src/services/PackageManagerService.php`
- `src/services/import/WebsiteImportService.php`
- `src/services/ComposerDependencyScanner.php`
- `src/services/installation/InstallationValidator.php`

## Second fix pass — private plugin dependencies, and the first real end-to-end run

Continuing past the first fix pass, the remaining "no capture/transport
mechanism for local plugin dependencies" gap was actually implemented (not
just detected), and the pipeline was run for real, repeatedly, until it got
further than it ever had before.

### Fix: bundle local `path`-repository plugins into the kit itself

`ai-chat`, `htmlsitemap`, and `payment-gateway` are Composer `path`
repositories - custom plugins whose source lives only inside rp-craft's own
`plugins/` directory. `ComposerDependencyScanner` now resolves each one's
actual source directory (by reading the `path` repo's own `composer.json`
for the package name it provides); `StarterKitBuilder::bundleLocalPlugins()`
copies that source into the kit's own `vendor-plugins/<handle>/` folder
(excluding `vendor/`, `node_modules/`, `.git/`) - which travels through the
existing export/import zip machinery completely unchanged, since a Starter
Kit's whole directory is already zipped/copied intact. `ComposerExecutor`
now transplants that bundled source into the target's `plugins/<handle>/`
and registers a matching `path` repository in the target's `composer.json`
*before* the batched `composer require` runs, so it resolves locally instead
of failing against Packagist. `InstallationValidator` recognizes a bundled
dependency as portable (info-level check, not a blocking error).

Verified live: Step 2 (Environment Validation) went from disabling
"Continue" with a clear per-package reason, to enabling it once the same
three packages were bundled into a regenerated kit.

### Finding + fix: package-name collisions on public Packagist

Running the **first real (non-dry-run) install** surfaced a new failure:
`remoteprogrammer/simple-rp-menu ^1.0.2` couldn't be resolved - Composer
found only `1.0.0`/`1.0.1`/dev branches. Direct investigation
(`ddev composer show remoteprogrammer/simple-rp-menu --all`) revealed why:
that package name resolves by default to a **completely unrelated**
Craft-3-only repository (`bedh-rp/simplerpmenu`) - rp-craft's own
composer.json only gets the correct, Craft-5-compatible one
(`rpqa99/craft5-simple-menu`, which does have `1.0.2`) because it declares
an explicit `vcs` repository entry pointing at it. That entry was never
captured. Fixed by adding
`ComposerDependencyScanner::captureNonDefaultRepositories()` (every
non-`path`, non-default repository the source site declares) and carrying
it through the blueprint → `InstallationPlanner` → `ComposerExecutor`,
which now registers it on the target's composer.json before requiring -
the same mechanism used for bundled `path` repos, generalized.

### A second environmental finding along the way

Before finding the real package-collision bug above, the same symptom was
initially (incorrectly) suspected to be a stale DDEV-wide Composer VCS
cache (`/mnt/ddev-global-cache/composer/vcs`, shared across all DDEV
projects on this machine) - `ddev composer clear-cache` was run as a
diagnostic step. It turned out not to be the actual cause here, but the
shared-cache fact itself is worth knowing for future debugging of any
"why does this resolve differently than I expect" report on this machine.

### Result: the first successful real Composer/plugin/npm layer, ever

With both fixes applied, a real (non-dry-run) install of a regenerated kit
completed **47 of 51 steps**: all 22 `composer require` calls (including the
3 previously-impossible bundled ones), all 22 plugin-install steps, the
frontend config copy, and the full `npm install` (20 packages) all
succeeded. This is the first time in this codebase's history the
Composer/plugin/npm layer of a real Starter Kit install has completed
successfully end-to-end - confirmed live in the browser (rp-craft-fresh's
own CP sidebar populated with AI Chat, Field Manager, HtmlSitemap, SEO,
Wheel Form, etc. after the run).

### Remaining failure: Sections/Entry Types are never actually created

**Category: Capture issue - a data-model gap, not an installer bug.**

The one step that still failed: *"Install captured pages and Global Set
values: 0 page(s) created, 4 template package(s) installed, 0 Global
Set(s) restored, 4 item(s) skipped."* Root-caused via the install session's
own stored progress log: each captured Section (`contact`/`footer`/`header`/
`home`) was skipped with *"it does not exist on this site yet (Sections
arrive via their Template package's own install, not this step)"* -
`CraftResourceInstallExecutor::applyCraftSection()` only ever **updates**
an existing Section's settings, by explicit design (see its own docblock:
"A Craft Section's own Entry Types/field layout are never created here").
That design assumes install always goes through a *Section*-type package
(which does create its own Entry Type via
`PackageManagerService::installPackage()`'s `type === 'section'` gate) -
but every page captured through the ordinary "page has no Site7 content"
path (`PageImportService::importNativeContent()`, which is what actually
handles a traditionally-built site's real pages, exactly as documented in
this same report's Step 1 findings) produces a **Template**-type package
instead, which never creates a Section or Entry Type at all.

Tracing one step further to see whether the installer could just create the
missing Entry Type itself: it can't, with what's captured today.
`importNativeContent()` computes each field's full structure
(`describeFieldLayout()`/`detectedFields`: handle, name, type, settings)
purely to classify it, but the final Template manifest only ever persists
the resulting **values** (`entryFields`) - never that structural schema.
`contact/manifest.json`'s `entryFields` is `{"formTitle": "...",
"showAddress": true, ...}`, plain scalars with no type information at all.
There is nothing left, once capture finishes, from which a field layout
could be rebuilt - this is a genuine gap in what gets captured, not
something fixable by changing install-time logic alone.

**Proposed fix (capture-side, not attempted this pass):** extend
`PageImportService::importNativeContent()`'s manifest to also persist
`detectedFields`' structural data (handle/name/type/settings) alongside the
existing values, versioned so existing already-captured Template packages
degrade gracefully (skip section creation, exactly as today) rather than
erroring on the new, richer schema. Then `CraftResourceInstallExecutor`
(or a new step) would create the missing Fields + Entry Type + Field
Layout from that data before `applyCraftSection()` runs, mirroring how
`CraftResourceService::createMatrixEntryType()` already does the equivalent
for Site7-content Matrix block types. This is a real feature addition
spanning capture and install, not a quick fix, and touches package-schema
versioning - a deliberate design decision, not something to improvise
mid-session.

### Files changed in this second pass (all uncommitted, pending review)

- `src/services/ComposerDependencyScanner.php` (path-repo source resolution + non-default repository capture)
- `src/services/StarterKitBuilder.php` (bundles local plugin source into the kit)
- `src/services/BlueprintBuilder.php` (carries `composerRepositories` through)
- `src/services/import/WebsiteImportService.php` (captures `composerRepositories` into the manifest)
- `src/services/installation/InstallationPlanner.php` (threads bundled-source/repository data into composer steps)
- `src/services/installation/InstallationValidator.php` (bundled dependency = portable, not an error)
- `src/services/installation/executors/ComposerExecutor.php` (transplants bundled source + registers repositories before requiring)

## Third fix pass — implementing Section/Entry Type auto-creation

The remaining gap from the second pass (Sections/Entry Types never created
for natively-authored pages, because only field *values* were captured, never
structure) was implemented, not just designed.

### Capture side: `PackageManifest::$fieldLayout`

`PageImportService::importNativeContent()` already computed each field's
full structure (`describeFieldLayout()`/`detectedFields`: handle, name,
type, settings) - it just discarded it after classification, keeping only
values. Now stores it 1:1 alongside `entryFields` as a new `fieldLayout`
manifest key. Existing already-captured packages simply have an empty
array here (safe default, degrades to the old skip-only behavior).

### Install side: build the missing Entry Type + Section, then the entry itself

- `CraftResourceService::createEntryTypeFromFields()` (new, public): builds
  Fields + an Entry Type + Field Layout from captured `fieldLayout` data,
  reusing the existing `createCraftField()` (made public - it already had
  a full read/write round-trip with `describeField()`, just never exposed).
- `StarterKitInstallationService::ensureEntryTypeAndSection()` (new): when
  a page's Entry Type doesn't exist yet, builds it via the above, then
  either attaches it to an already-existing Section or creates the Section
  itself from this Starter Kit's own captured `craftSections` settings.
- **Finding along the way:** `Craft::$app->getEntries()->saveEntryType()`
  silently *overwrites* whatever `hasTitleField` was set on the model,
  deriving it instead from whether the Field Layout contains a native title
  element (`$entryType->hasTitleField = $entryType->getFieldLayout()->isFieldIncluded('title')`).
  Every Entry Type built here therefore ended up with no title field and no
  way to ever set one, regardless of the constructor argument - fixed by
  adding a real `craft\fieldlayoutelements\entries\EntryTitleField` to the
  layout, not just passing `hasTitleField: true` and trusting it to stick.
  Verified directly: before the fix, a freshly created Entry Type's
  `hasTitleField` was `0` in the database despite passing `true`; after,
  it's `1`.
- **Finding along the way:** `TemplateInsertionService::createEntryFromTemplate()`
  - the pre-existing, only insertion mechanism - is hard-wired to Site7
  Matrix-block content (`getTemplateBlocks()`) and throws for any Template
  with none, which describes every natively-captured page. Added
  `StarterKitInstallationService::createOrUpdateNativeEntry()` as a
  parallel path: sets the entry's title and each native field's captured
  value directly, and - since Craft auto-creates a `single`-type Section's
  one-and-only Entry the moment the Section itself is saved - finds and
  updates that existing entry rather than invalidly creating a second one.

### Verified

- Direct DB check after a real install: 4 Sections (`contact`/`footer`/
  `header`/`home`, all correctly `single` type) and their Entry Types now
  exist, where before nothing did.
- Field values populate correctly and match the source site exactly (e.g.
  `contact`'s entry: `formTitle: "Get a free consultation"`, `showAddress:
  true`, etc.) - a genuine, verified value round-trip from rp-craft's real
  content to a completely separate fresh Craft installation.
- The `hasTitleField` fix verified in isolation (a freshly built Entry Type
  now saves with `hasTitleField = 1`, confirmed via both PHP and a direct
  SQL query) - not yet re-verified through one more full end-to-end run
  with a from-scratch database, since the target site's `config/project/`
  already has the pre-fix Entry Type definitions baked in and a DB wipe
  alone replays them unchanged; a true from-scratch re-verification would
  need that project config cleared too.

### Files changed in this third pass (all uncommitted, pending review)

- `src/models/packages/PackageManifest.php` (new `$fieldLayout` property)
- `src/services/import/PageImportService.php` (captures field structure, not just values)
- `src/services/CraftResourceService.php` (`createEntryTypeFromFields()`, `createCraftField()` made public, title-field fix)
- `src/services/StarterKitInstallationService.php` (`ensureEntryTypeAndSection()`, `createOrUpdateNativeEntry()`)

### What's left

Not yet re-verified with one clean from-scratch install (DB + project
config both cleared) incorporating all three fix passes together - the
individual pieces are each verified in isolation or against a
partially-installed site, but not yet as one single, uninterrupted
fresh-install run. Recommended as the next step before considering this
capability complete.

## Appendix: cleanup performed

All test artifacts created during this validation were removed from
`rp-craft` afterward (packages generated by repeated capture/generation
attempts, temporary `ScratchDebugController` console commands used to drive
services directly — none were committed, per the project's established
practice). `rp-craft-fresh` (the provisioned fresh DDEV site) was left in
place in case further live debugging of the findings above is wanted before
they're fixed in code; let the team know if it should be torn down instead.
