# Phase 7 — Fresh-Install Setup Wizard

Status: **Implemented, awaiting architectural review before Phase 8.** Per `WEBSITE-STARTER-KIT-SYSTEM.md` Phase 7 - the user-facing wizard that orchestrates Phases 1-6's existing installation infrastructure. Verified against a genuinely fresh, throwaway Craft 5 + DDEV project (`site7-freshtest7`) provisioned solely for this phase and torn down afterward, not the shared rp-craft dev site.

## Architecture: the wizard is presentation/orchestration only

```
Starter Kit (packages/<handle>/manifest.json + blueprint.json)
  -> StarterKitCatalogService::listAvailable()/getBlueprint()   read-only, Wizard Step 1
  -> InstallationSessionService::create()                        persists an InstallationSession
  -> InstallationStageRunner::runNextStage()                     re-plans/re-validates/executes ONE stage
       -> InstallationPlanner::plan()            unchanged, Phase 6
       -> InstallationValidator::validateInstallation()  unchanged, Phase 6
       -> InstallationExecutor::execute()         unchanged, Phase 6 - given a filtered, single-stage InstallationPlan
  -> InstallationOrchestratorService::runToCompletion()          spawns one fresh subprocess per stage, loops until done
  -> InstallStarterKitJob (Craft queue) / InstallController (console)   the only two things that call the orchestrator
  -> InstallWizardController                                     CP presentation layer - never calls InstallationExecutor directly
```

No file in this phase reimplements anything Phase 6 already built. `InstallationStageRunner` calls the exact same `InstallationPlanner`/`InstallationValidator`/`InstallationExecutor` Phase 6 shipped, unmodified except for one visibility bump (`InstallationValidator::MIN_PHP_VERSION` made `public` so Step 1 can display it without duplicating the constant).

## The concrete problem this phase had to solve

Phase 6's own live testing proved a single unbroken PHP process cannot both `composer require` a new plugin and then `Plugins::installPlugin()`/`enablePlugin()` it - Craft caches its Composer-plugin manifest once per process. Phase 6 left the actual fix as Phase 7's problem. This phase's own live testing then found a **second, independent instance of the same class of bug**: rebuilding Project Config in the same process as a plugin-install step that just ran intermittently wrote that plugin back out as *not enabled* in `config/project/project.yaml` - the freshly-enabled state hadn't fully propagated through Craft's own Project Config internals within that same request. The next request's automatic Project Config sync then genuinely disabled the plugin again, confirmed by literally watching `craft plugin/list` flip a plugin from `Yes/Yes` to `Yes/No` between runs with no explicit uninstall command ever succeeding.

Both findings point at the same underlying rule: **anything that reads back Craft's own runtime state as ground truth (a plugin manifest, Project Config's own rebuild) cannot trust state written earlier in the same PHP process.** The fix is the same both times - a real OS-level process boundary, not just careful ordering within one script.

## InstallationSession - the object that survives those process boundaries

`src/models/installation/InstallationSession.php` - a plain, mutable, non-Model class (deliberately *not* `craft\base\Model`; see the naming-collision note below), holding: `starterKitHandle`, `packagePath`, `dryRun`, `status`, `currentStep`, `blueprint`, `validationResult`, `plan` (display-only - never replayed verbatim across a process boundary, since every stage re-plans fresh), `stagesCompleted`, `stageResults`, `progressLog`, `fatalError`. Persisted by `InstallationSessionService` (`src/services/installation/InstallationSessionService.php`) into a new table, `site7_install_sessions` (migration `m260730_130000_create_install_sessions_table`), one JSON blob per row keyed by `uid`.

### Stage order

```php
STAGE_ORDER = [STAGE_COMPOSER, STAGE_INSTALL, STAGE_PROJECT_CONFIG];
```

- `composer` - `InstallationStep::TYPE_COMPOSER` steps only.
- `install` - everything except composer and project-config (`plugin-install`, `craft-resource`, `content`, `frontend`, `npm`).
- `project-config` - the final `project-config` rebuild step, alone.

Each stage boundary is a real subprocess spawn (see `InstallationOrchestratorService` below), so `composer`/`install` and `install`/`project-config` each get a fresh Craft app boot. A stage with nothing to do (e.g. `composer` on a re-install where every plugin is already required, or `project-config` after a dry run) is skipped in the *same* call rather than spending a subprocess hop on it - confirmed live: a second install run against an already-installed kit correctly skipped straight from validation into the `install` stage with zero composer subprocess spawned.

### Critical-failure semantics, checked per step type, not per stage

`STAGE_INSTALL` bundles a critical step type (`plugin-install`) with several non-critical ones (`craft-resource`/`content`/`frontend`/`npm`). A failed `content` step must **not** block the later `project-config` stage from running - this exactly mirrors `InstallationExecutor`'s own single-process critical-type behavior (`InstallationStep::TYPE_COMPOSER`/`TYPE_PLUGIN_INSTALL` only). `InstallationStageRunner::CRITICAL_TYPES` duplicates that list rather than exposing `InstallationExecutor`'s private constant just for this. Verified live: a hand-authored test kit's `content` step failed (`Starter Kit package not found` - correctly, since the kit intentionally had no packaged content), and `project-config` still ran to completion in the very same run.

## InstallationOrchestratorService - the real process boundary

`src/services/installation/InstallationOrchestratorService.php` loops `InstallationStageRunner::runNextStage()` calls, but never invokes the stage runner directly in its own process. Every stage runs inside a freshly spawned `php craft site7-studio/install/run-stage <uid>` subprocess (Symfony Process, the same library `NpmExecutor` already uses - no new dependency). This is deliberate: a generic Craft queue runner (`queue/run`/`queue/listen`) processes many jobs in one long-lived loop *within a single process*, which would not have caught either of the two findings above. Spawning a real subprocess per stage is what actually guarantees the separation Phase 6's finding required, regardless of whatever ends up calling the orchestrator (queue job today, a future console supervisor or Cloud runner without any change to this class).

Both the CP wizard's queue job and the console command call this exact same class, so neither entry point duplicates the stage-boundary logic.

## Entry points

- **`InstallWizardController`** (`src/controllers/InstallWizardController.php`) - `actionIndex` (Step 1, lists Starter Kits via `StarterKitCatalogService`), `actionValidate` (Steps 2/3, builds the session + runs `InstallationPlanner`/`InstallationValidator` synchronously - always safe, since planning/validating never mutates anything), `actionExecute` (Step 4 - pushes `InstallStarterKitJob` onto Craft's queue and returns immediately), `actionProgress` (polled by the wizard's JS), `actionSummary` (Step 5, server-rendered from the session's merged stage results). No action here ever calls `InstallationExecutor` or `InstallationStageRunner` directly.
- **`InstallStarterKitJob`** (`src/jobs/InstallStarterKitJob.php`) - a `craft\queue\BaseJob` whose `execute()` is one line: `InstallationOrchestratorService::runToCompletion()`. Reports coarse progress via Craft's own queue progress bar; the wizard's own polling of the `InstallationSession` record (updated live by each stage's subprocess) is the actual source of step-by-step detail.
- **`InstallController`** (`src/console/controllers/InstallController.php`) - `actionList`/`actionValidate` (CLI equivalents of Steps 1-3), `actionRun` (creates a session and calls the orchestrator directly, printing progress as it polls between subprocess calls - no queue involved, for pure-CLI use), `actionRunStage` (the actual subprocess entry point `InstallationOrchestratorService` spawns - never invoked directly by a user).

### CP templates and JS

`src/templates/install-wizard/{index,preview,summary}.twig` + `src/resources/js/install-wizard.js` (a `Craft.getActionUrl()`/`fetch()`-based single-page flow through Steps 1-4, following the exact conventions `resource-import-wizard.js` already established) + `src/resources/css/install-wizard.css`. Step 4 polls `actionProgress` every 1.5s and redirects to the server-rendered Step 5 summary once the session reports `done: true`.

## A third base-class naming collision, caught only by live execution

Following the exact same pattern Phases 3, 5, and 6 already hit: `InstallationSessionService::load(string $uid)` collided with `yii\base\Model::load($data, $formName = null)` - **`craft\base\Component` itself extends `craft\base\Model` extends `yii\base\Model`**, not a lighter base as assumed while writing the class; this only surfaced as a fatal "incompatible declaration" error when actually booting a live Craft app (a `require`'d autoloader boot, not `php -l`, not a Craft-independent unit test). Renamed to `loadSession()`. Also caught, and confirmed *not* a real collision this time: `save()`/`delete()` on the same class - `yii\base\Model` doesn't declare either (those are `ActiveRecord`-only), verified directly by successfully calling both in the same live boot. Every new class in this phase was swept with a small reflection script checking for `load`/`validate`/`save`/`delete`/`fields`/`attributes`/`behaviors`/`scenarios`/`rules` before live testing began, but the sweep only flags candidates - the live boot is what actually proves which ones are real.

## A pre-existing gap, discovered but out of this phase's scope

Live-testing a *genuinely* fresh install (rather than the already-long-running rp-craft dev site) surfaced that `craft plugin/install site7-studio` seeds every one of the plugin's numbered migrations into the `migrations` history table as already-applied **without running their `safeUp()`** - on a truly fresh install, only `Install.php` actually executes; Craft assumes a plugin's `Install.php` migration alone produces the fully current schema, and numbered migrations exist only to upgrade an already-installed instance. Since `Install.php` here predates most of the plugin's tables (`site7_packages`, `site7_shared_resources`, this phase's own `site7_install_sessions`, etc.), a genuinely fresh install of this plugin currently ends up with **none of those tables actually created** until someone manually clears the stale history rows and re-runs `craft migrate/up --plugin=site7-studio`. This was worked around for this phase's own verification (by doing exactly that) but was not fixed here - rewriting `Install.php` to contain the plugin's full current schema is a cross-cutting change touching every phase's migration boundary, not a Phase 7 concern. Flagged here for the architectural review before Phase 8.

## Tests

- `tests/unit/models/installation/InstallationSessionTest.php` - stage sequencing (`nextStage()` walks `composer -> install -> project-config -> null`), `isDone()`, `appendLog()`, `mergedStageResults()` (confirms no `warnings` key - see below), and a `toArray()`/`fromArray()` round trip (the exact shape `InstallationSessionService` persists).
- `tests/unit/services/installation/InstallationStageRunnerTest.php` - all three stages running as three separate calls with fake `InstallationPlanner`/`InstallationValidator` (subclassed to bypass their live-Craft-app-dependent methods, same rationale `InstallationPlannerTest` already documents for `planPlugins()`) and a real `InstallationExecutor` configured with fake `StepExecutorInterface`s (matching `InstallationExecutorTest`'s existing pattern exactly); empty-stage fast-skip (both leading and trailing); validation failure short-circuiting before any executor call; a critical (`composer`) failure halting before `install` runs; a critical (`plugin-install`) failure halting before `project-config` runs; and the regression this phase's live testing actually found - a **non-critical** (`content`) failure inside `STAGE_INSTALL` does *not* block `project-config`.

This host still has no PHPUnit/Codeception binary (same as Phase 6), so every assertion above was additionally hand-verified via direct PHP scripts against the plugin's real autoloader - which is exactly how the `load()` collision and the stage-critical-failure regression described above were actually caught, before they ever reached the live DDEV site.

`InstallationReport`'s `warnings` field always echoes the full `ValidationResult.warnings` verbatim on every `InstallationExecutor::execute()` call; `InstallationSession::stageResults` deliberately excludes `warnings` to avoid duplicating them across three stage calls - the final summary's warnings come straight from `session.validationResult` instead (see `summary.twig`).

## Live verification

Against a genuinely fresh, throwaway Craft 5.10 + DDEV project (`site7-freshtest7`), composer-created from scratch and linked to the plugin via a Composer path repository (plugin source copied into the fresh project's own tree, since a path repository's `url` must resolve inside the target project - unlike a plain `packages/` Starter Kit, which lives inside the plugin's own directory via the `@packages` alias). Torn down completely afterward (`ddev delete -Oy`, directory removed).

Verified with a hand-authored, schema-faithful test Starter Kit (`phase7-test-kit`: one real, resolvable Composer package - `craftcms/ckeditor`, matching Phase 6's own proven choice - plus one Category Group, deliberately no content, to isolate the wizard/orchestration layer this phase actually adds from Phase 5's capture-pipeline correctness, already proven in Phase 6 against real content).

1. **Step 1 (Select Starter Kit)** - both console (`site7-studio/install/list`) and CP (`/admin/site7-studio/install`) correctly listed the kit with name/version/description/required Craft+PHP version/plugin count, read straight from `manifest.json`+`blueprint.json`.
2. **Steps 2-3 (Validate + Preview)** - `site7-studio/install/validate` and the CP's Validate step correctly reported per-check pass/fail, the "not installed - will require a Composer + plugin-install step" warning, and the exact planned step list.
3. **Dry Run** - every step reported `skipped`; confirmed via direct DB query that zero Category Groups existed afterward.
4. **First real install** - `composer` and `install` stages genuinely ran as two separate subprocesses; `craftcms/ckeditor` was actually pulled in by Composer and then actually installed+enabled by a *second* process (confirmed via `craft plugin/list` showing `Yes/Yes`) - the exact fix for Phase 6's own finding, proven working end-to-end for the first time. The Category Group was actually created (confirmed via direct DB query). The content step correctly failed (`Starter Kit package not found` - accurate, since this test kit has none) without stopping `project-config`, which ran and completed.
5. **Idempotent re-run** - re-running the same install correctly skipped the `composer` stage entirely (already required) in the *same* call, reported the plugin step as `Already installed and enabled`, updated the existing Category Group in place (confirmed no duplicate row), and failed the content step identically.
6. **The Project Config regression, found and fixed live** - an early run showed `craft plugin/list` reporting `ckeditor` as `Yes/No` (installed, not enabled) despite a prior run's step log claiming success, traced to `config/project/project.yaml` missing `enabled: true` for that plugin. Isolating `project-config` into its own final stage/process (rather than bundling it with `install`) resolved it; confirmed by re-running and seeing the plugin stay `Yes/Yes` across the entire run.
7. **CP wizard, end to end, in a real browser** - logged into the fresh site's CP, drove Steps 1 through 4 (Select -> uncheck Dry Run -> Validate -> Continue -> Execute), confirmed the queued `InstallStarterKitJob` actually ran via `craft queue/run`, and confirmed the wizard's own JS polling correctly redirected to the server-rendered Step 5 Summary page showing Completed/Skipped/Failed/Warnings/Errors and a "Next Steps" callout - matching the same session the console-driven runs had already produced.
8. **Failure handling surfaced correctly** throughout - the content-step failure was never swallowed or misreported as success at any layer (step log, session status, final summary), and a deliberately invalid Blueprint (missing `blueprint.json` entirely) was correctly rejected by `StarterKitCatalogService` at Step 1 (`installable: false`, with a note) before a session was even created.

A CSS bug found during this same live pass (the Step 5 status badge briefly using a Craft CP class name (`status-badge`) that collided with an unrelated real Craft style, rendering "Failed" as vertically-stacked single characters) was fixed by using this phase's own namespaced classes (`s7-result-badge--success`/`--failure`) instead - a reminder that even a CSS class name deserves the same "does this already exist and mean something else" check as a PHP method name in this codebase.

## Scope boundaries carried forward

No Marketplace or Cloud installation UI (explicitly designed to be reusable by both later, per this phase's own UX requirements, but neither is wired up yet). No sync/update mechanism - re-running this wizard against an already-installed kit works safely (idempotent, confirmed live) but doesn't detect or reconcile drift; that is explicitly Phase 8's concern. The pre-existing fresh-install migration gap described above is flagged, not fixed. No attempt was made to route around the one Filesystem/Section scope boundary Phase 6 already documented (Asset Volumes/Sections referencing not-yet-existing target resources still skip cleanly, unchanged).
