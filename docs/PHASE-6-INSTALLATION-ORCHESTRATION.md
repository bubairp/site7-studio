# Phase 6 — Installation Orchestration Infrastructure

Status: **Implemented, awaiting architectural review before Phase 7.** Per `WEBSITE-STARTER-KIT-SYSTEM.md` Phase 6 — the first phase that mutates a target Craft CMS installation. Verified against a genuinely fresh, throwaway Craft 5 + DDEV project provisioned solely for this phase, not the shared rp-craft dev site.

## Architecture: planning strictly separate from execution

```
Blueprint (Phase 5)
  -> InstallationPlanner::plan()       read-only, produces an InstallationPlan
  -> InstallationValidator::validateInstallation()   read-only, produces a ValidationResult
  -> InstallationExecutor::execute(plan, validationResult, dryRun)
       -> delegates each step, by type, to a dedicated StepExecutorInterface implementation
       -> produces an InstallationReport
```

`InstallationExecutor` **never** accepts a Blueprint directly - only an already-built `InstallationPlan`, and only alongside a `ValidationResult` it checks before doing anything. An invalid `ValidationResult` short-circuits the whole run with zero steps executed, regardless of `dryRun`.

### New models (`src/models/installation/`)

- `InstallationStep` - one unit of work: `{type, key, label, payload}`. `key` is a stable idempotency identity (a plugin handle, a Volume handle, a Composer package name) - never a runtime ID.
- `InstallationPlan` - **distinct from Phase 5's `site7\studio\models\project\InstallationPlan`** (which describes dependency *ordering* for the Blueprint). This one is the flat, ordered list of executable `InstallationStep`s plus the planner's own `dependencyValidation` findings (e.g. "this plugin is already required in the target's composer.json").
- `ValidationResult` - `{valid, errors, warnings, checks}` - a pure read-only pre-flight report.
- `InstallationReport` - the final output: `{success, completedSteps, skippedSteps, failedSteps, warnings, errors, validationResult}`.

## InstallationPlanner

`src/services/installation/InstallationPlanner.php`. Turns a Blueprint into an ordered `InstallationPlan`, reading the target environment only to decide which steps are actually needed (e.g. no `composer` step for a plugin package already in the target's `composer.json`) - it never installs, runs, or mutates anything. Step order is fixed and reflects real dependency order:

```
composer -> plugin-install -> craft-resource -> content -> frontend -> npm -> project-config
```

(A plugin's code must be pulled in by Composer before Craft can install/enable it; Craft resources must exist before content referencing them is installed; Project Config is rebuilt last so every element-level save above is reflected in `config/project/` immediately.)

`planCraftResources()`/`planContent()`/`planFrontend()` are `public static` (pure array shaping, Craft-independent) specifically for direct unit testing; `planPlugins()` needs `ComposerDependencyScanner` (a live Craft app) and is covered by live verification instead.

## InstallationValidator

`src/services/installation/InstallationValidator.php`. Dry-run by construction - every check is read-only:

- **Blueprint integrity** - every required top-level key present, and the Blueprint's own `validation.valid` (Phase 5's build-time check) wasn't already `false`.
- **Required plugins** - installed/enabled status per plugin (informational, not blocking - not yet installed just becomes a warning).
- **Composer availability** - Craft's own bundled `composer.phar` (`@lib/composer.phar`) and a resolvable PHP executable, since Phase 6 uses Craft's official Composer service, not a system-wide `composer` binary.
- **npm availability** - only checked when the Blueprint actually needs npm; resolved via `Symfony\Component\Process\ExecutableFinder`.
- **Environment compatibility** - PHP version.
- **Filesystem prerequisites** - target's `composer.json` writable.

`validateInstallation()` (see naming note below) can run standalone against a Blueprint alone, or alongside an `InstallationPlan` to fold in the planner's own dependency findings.

## InstallationExecutor + dedicated sub-executors

`src/services/installation/InstallationExecutor.php` contains **no installation logic of its own** - it groups the plan's steps by type and delegates each group to whichever `StepExecutorInterface` is registered for that type:

| Step type | Executor | What it does |
|---|---|---|
| `composer` | `ComposerExecutor` | `Craft::$app->getComposer()->install($requirements)` - the exact official API Craft's own Plugin Store uses. Batches every composer step into one call (matches how the underlying `composer update` command actually works); that service already backs up/restores `composer.json`/`composer.lock` on failure. |
| `plugin-install` | `PluginInstallExecutor` | `Plugins::installPlugin()`/`enablePlugin()` - idempotent, skips if already installed+enabled. |
| `craft-resource` | `CraftResourceInstallExecutor` | Creates/updates Asset Volumes, Category/Tag Groups, and Section settings via official service APIs (`saveVolume()`/`saveGroup()`/`saveTagGroup()`/`saveSection()`) - idempotent (find-by-handle, update if found). See scope boundaries below. |
| `content` | `ContentInstallExecutor` | Thin wrapper around the existing, already-verified `StarterKitInstallationService` (Phase 1) - not reimplemented. |
| `frontend` | `FrontendInstallExecutor` | Copies the package's own captured `frontend/` config files into place on the target (existing project if detected, else a conventional `frontend/` fallback). |
| `npm` | `NpmExecutor` | `npm install` via Symfony Process (no equivalent official Craft service exists for npm) - explicitly does **not** run a build script. |
| `project-config` | `ProjectConfigExecutor` | `Craft::$app->getProjectConfig()->rebuild()` - the same call `PackageManagerService::invalidateCraftCaches()` already makes elsewhere in this plugin. Never touches `config/project/*.yaml` directly; every step above already writes to Project Config via official element/service APIs. |

**Two deliberate scope boundaries** in `CraftResourceInstallExecutor`, both because no phase so far captures the data needed to do more safely:
- An Asset Volume's filesystem (`fsHandle`) must already exist on the target - Filesystems aren't captured by any phase, and auto-provisioning one (path/permissions) is its own feature. A missing filesystem skips that one step with a clear message, not a crash.
- A Craft Section's own Entry Types/field layout are never created by this executor - those arrive from the Section/Template package's own install cascade (existing `PackageManagerService`/`craftResourceGenerator` infrastructure, unmodified). A Section that doesn't exist yet is skipped, not partially created.

**Critical vs. non-critical failures**: a failed `composer`/`plugin-install` step stops the whole run (everything after it would be operating on a target whose real state no longer matches what the plan assumed). A failed `craft-resource`/`content`/`frontend`/`npm`/`project-config` step is reported but doesn't stop later steps, since those are independent of each other.

`InstallationExecutor::$executorsByType` is public and Yii-config-constructible specifically so tests can substitute fake `StepExecutorInterface` implementations.

## Two more base-class naming collisions, caught only by live execution

Following the same pattern Phases 3 and 5 already hit:

- `InstallationValidator::validate()` collided with `yii\base\Model::validate($attributeNames, $clearErrors)` (public; a private/incompatible override is a fatal error) - renamed to `validateInstallation()`.

This is now the **third** time this exact class of bug has surfaced in this initiative, always at live-execution time, never via `php -l` or a Craft-independent unit test. `craft\base\Component` (every service in this plugin) descends from `yii\base\Model`, which reserves `load`, `validate`, `fields`, `attributes`, `behaviors`, `scenarios`, and others - worth checking before naming any new public/protected method on a class in this codebase.

## A real architectural discovery from live testing: plugin install needs a process boundary after Composer

The single most important finding from this phase's live verification: **installing a plugin via Composer and then enabling it via `Plugins::installPlugin()`/`enablePlugin()` cannot both happen within the same PHP process.**

Craft's `Plugins::init()` reads `vendor/craftcms/plugins.php` (the Composer-generated manifest of installed Craft plugins) exactly once and caches it in a private property for the lifetime of that `Craft::$app` instance. `Craft::$app->getComposer()->install()` correctly triggers `craftcms/plugin-installer`'s own Composer-plugin hook to regenerate that file on disk - confirmed live, the file was correctly updated with the newly-required plugin's entry - but the *already-booted* `Plugins` service in the same process has no way to know that happened, and Craft exposes no public method to force a reload. Attempting `installPlugin()` immediately after `getComposer()->install()` in the same script reliably fails with `"No plugin exists with the handle ..."`, even though the plugin is correctly on disk and correctly registered in the regenerated manifest.

This was proven directly: running the `composer` step and the `plugin-install` step in the same PHP invocation failed exactly this way; splitting them into two separate `ddev exec php ...` invocations (a fresh process for the second one) succeeded immediately, with the plugin correctly installed and enabled.

**This has a direct, concrete consequence for Phase 7's execution model** (the master plan's own Open Question #1, "synchronous request vs. queue job vs. console command"): whatever executes an `InstallationPlan` for real cannot be a single unbroken PHP call when both `composer` and `plugin-install` steps are present - there must be a process boundary between them (e.g., a queue job that requeues itself, or a console command that re-invokes itself, after the Composer step completes). `InstallationExecutor`'s step-type grouping already gives a natural seam for this (composer steps and plugin-install steps are handled by entirely separate executor calls) - a future multi-process orchestrator can run each type as its own process without changing anything in this phase's code, but a naive single-call synchronous wrapper (e.g. a plain CP request) will not work once any new plugin is involved.

## Tests

- `tests/unit/models/registry/ResourceGraphTest.php`, `tests/unit/services/DependencyAnalyzerTest.php` - unchanged from Phase 5.
- `tests/unit/services/installation/InstallationPlannerTest.php` (new) - `planCraftResources()` covers all four resource kinds with correct keys; `planContent()` produces one step keyed by package handle; `planFrontend()` includes both the config-copy and npm steps only when the package's own `frontend/` directory actually exists (a real temp-directory check, not mocked), and correctly omits steps when there's nothing to do.
- `tests/unit/services/installation/InstallationExecutorTest.php` (new) - using fake `StepExecutorInterface` implementations (no real executor, no Craft app): all-completed yields success; a failing `ValidationResult` skips execution entirely without calling any executor; a critical-type (`composer`) failure stops and skips every subsequent step; a non-critical (`craft-resource`) failure does **not** stop subsequent steps; the `dryRun` flag is correctly passed through to each executor.

This host still has no PHPUnit/Codeception binary, so every assertion above was additionally hand-verified via a direct PHP script.

## Live verification

Against a genuinely fresh, throwaway Craft 5.10 + DDEV project (`site7-freshtest`) provisioned solely for this phase - composer-created from scratch, installed via `craft install`, with the site7-studio plugin linked via a Composer path repository and installed/enabled through the normal plugin lifecycle - **not** the shared rp-craft dev site. Torn down completely afterward (`ddev delete`, directory removed).

A real Starter Kit package was built on the source rp-craft project (via the full Phase 5 `StarterKitBuilder` pipeline, entry #8293 "Portfolio 06" again) and physically copied into the fresh site's `packages/` directory - deliberately *without* its required Template package, to honestly exercise the graceful-skip path for content that references not-yet-installed schema, rather than needing to replicate the entire pre-existing Section/Pattern/Template package ecosystem into a throwaway site.

1. **Planning is deterministic** - `InstallationPlanner::plan()` called twice against the same Blueprint produced byte-identical `InstallationPlan`s. Step order confirmed: `composer` steps (22, all missing since the target is bare) → `plugin-install` steps (22) → `craft-resource` steps (5: 1 volume, 2 category groups, 1 tag group, 1 section) → `content` → `frontend` → `npm` → `project-config` (last).
2. **Dry-run mutates nothing** - every one of the ~50 steps reported `skipped`, and `Sections`/`Volumes`/`Category Groups` counts on the fresh site remained exactly 0 throughout.
3. **Dependency validation** - correctly reported all 22 required plugins as "not installed - will require a Composer + plugin-install step" (warnings, not errors, since installability isn't known to be impossible); overall `valid: true`.
4. **Real execution against the fresh site** (composer/plugin-install steps excluded from this run - see below): 2 Category Groups and 1 Tag Group **actually created** on the previously-bare site; the Asset Volume step **correctly skipped** (its `fsHandle` "public" doesn't exist on a filesystem-less fresh install - the documented boundary, not a crash); the Section-settings step **correctly skipped** ("portfolios" Section doesn't exist yet - the documented boundary); the content step **correctly failed with a clear message** (`0 page(s) created ... 1 item(s) skipped` - the Template package genuinely wasn't transferred); `frontend` copied 4 real config files; `npm install` **actually ran** (204 real packages in `node_modules`, a real `package-lock.json` generated); `project-config` rebuild completed. Overall report: `success: false` (correctly, given the content-step failure), 6 completed / 2 skipped / 1 failed.
5. **Idempotency** - re-running the exact same execution a second time left Category Group/Tag Group counts unchanged (no duplicates) - confirmed via `getGroupByHandle()`/`getTagGroupByHandle()` lookups finding the existing resource and updating it in place.
6. **Composer + plugin install, proven with a real resolvable package** - the source project's real captured plugin list included packages this sandboxed test genuinely could not resolve (`remoteprogrammer/simple-rp-menu`'s captured constraint `^1.0.2` matches no published version, and it turned out to be a Craft 3-only plugin incompatible with this Craft 5 site regardless of constraint - real facts about that specific package, not a Phase 6 defect; several `remoteprogrammer/*` packages are private and were never captured with their custom Composer repository, since Phase 4 only captures `require` entries). Proven instead with `craftcms/ckeditor` (official, Craft-5-compatible): the `composer` step completed, correctly regenerating `vendor/craftcms/plugins.php` on disk - and the process-boundary finding above was discovered and then confirmed exactly this way.
7. **Failure reporting** - a deliberately broken Blueprint (missing `packageHandle`) was correctly rejected by both the planner (`dependencyValidation.errors`) and the validator (`valid: false`); `InstallationExecutor` correctly refused to execute any step against the failed `ValidationResult`.

## Scope boundaries carried forward

Per this phase's explicit scope: no Marketplace, Cloud synchronization, or update mechanism. No build-tool execution (`npm run build`) - only `npm install`. No Filesystem provisioning, no Section/Entry-Type-from-scratch creation (both explicitly out of scope for the reasons given above). No multi-process/queue orchestration itself - `InstallationExecutor` runs synchronously end-to-end within one process, which this phase's own live testing showed is insufficient once a `composer` step is followed by a `plugin-install` step; that orchestration (queue job, console command, or similar) is Phase 7's concern.
