# 32 — Starter Kit System

## 1. Purpose

Document the largest subsystem in the plugin — whole-site capture, build, subprocess-based installation, and synchronization of Starter Kit packages. This is architecturally distinct from (and NOT merged with) the single-file three-way system documented in `19_UPDATE_AND_CONFLICT_HANDLING.md`.

## 2. What It Does

Captures an entire Craft site's structure (or a curated subset) into a portable `blueprint.json`, then installs it onto a target site through a carefully staged, subprocess-isolated executor — because installing a Composer plugin and enabling it via Craft's Plugins service cannot safely happen in the same PHP process.

## 3. Current Status

**Implemented** — build, install (with subprocess orchestration), and whole-site sync are all live. See `15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md` §14 for confirmed real-world capture gaps in the underlying Website import step.

## 4. Architecture

```
BUILD SIDE
  WebsiteImportService (capture, 15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md)
     ↓
  ProjectBuilder — assembles a Project model, adds PlatformConfigService summary
     ↓
  DependencyAnalyzer — computeClosure(): BFS over whole-project CraftResourceRegistry
     graph, narrowed to what THIS manifest needs; produces ordered waves:
     plugins / schema / content / frontend, plus cyclicResources/dependencyRelationships
     ↓
  BlueprintBuilder — Project + InstallationPlan → plain JSON blueprint.json,
     SCHEMA_VERSION = '1', deliberately independent of .s7pkg/manifest.json shape
     ↓
  StarterKitBuilder — top-level orchestrator, writes blueprint.json alongside
     the package's manifest.json (build-only scope: no install/Composer/npm execution)

INSTALL SIDE  (subprocess-per-stage)
  InstallationPlanner — Blueprint → deterministic ordered InstallationPlan (read-only)
     ↓
  InstallationValidator — pure read-only pre-flight checks, dry-run by construction
     ↓
  InstallationExecutor — executes a built plan via per-type StepExecutorInterface:
     ComposerExecutor / ContentInstallExecutor / CraftResourceInstallExecutor /
     FrontendInstallExecutor / NpmExecutor / PluginInstallExecutor / ProjectConfigExecutor
     ↓
  InstallationStageRunner — runs exactly ONE session stage per call, three stages:
     'composer' alone → 'install' (plugin/craft-resource/content/frontend/npm) → 'project-config' alone
     ↓
  InstallationOrchestratorService — spawns `php craft site7-studio/install/run-stage <uid>`
     as a FRESH OS SUBPROCESS for every stage (Symfony\Component\Process\Process,
     STAGE_TIMEOUT_SECONDS = 900)

SYNC SIDE  (whole-site, structurally separate from 19_UPDATE_AND_CONFLICT_HANDLING.md)
  SynchronizationPlanner — diffs previously-installed Blueprint vs a newer one,
     classifies changes, produces SynchronizationSteps shaped like Phase 6's
     InstallationSteps (re-executed via the SAME install machinery — idempotent by design)
     ↓
  SynchronizationValidator — read-only: Craft version, plugin version (Composer\Semver\Semver),
     Project Config drift, npm availability
     ↓
  SynchronizationOrchestratorService — applies a confirmed SynchronizationPlan by re-running
     Phase 7's InstallationSession + InstallationOrchestratorService UNCHANGED against the
     new Blueprint
     ↓
  InstalledStarterKitTrackingService — pure persistence via InstalledStarterKitRecord,
     recordInstall(handle, version, blueprint) — this IS the baseline every sync plan diffs against
```

## 5. Execution Flow

**Build**: `StarterKitBuilder::build()` → `ProjectBuilder` assembles the `Project` → `DependencyAnalyzer::computeClosure()` narrows the whole-project graph → `BlueprintBuilder` serializes to `blueprint.json`.

**Install** (why subprocess-per-stage — verbatim from `InstallationOrchestratorService`'s docblock):
> "Every stage is executed inside its own freshly spawned `php craft site7-studio/install/run-stage <uid>` subprocess instead, which is what actually gives Phase 6's proven finding a real fix: installing a plugin via Composer and then enabling it via Craft's Plugins service cannot happen in the same PHP process (Craft caches its Composer-plugin manifest once per process). A generic Craft queue runner alone doesn't guarantee that separation — `queue/run`/`queue/listen` process many jobs in one long-lived loop within a single process — so this class provides the actual OS-level process boundary explicitly."

Both `InstallStarterKitJob` (queue job) and `InstallController::actionRun` (console) call `runToCompletion()`, which loops spawning `runStageSubprocess()` until `$session->isDone()`. The console entry point for each individual stage is `InstallController::actionRunStage(string $sessionUid)`, which calls `installationStageRunner->runNextStage($session)` — **not meant for direct human invocation** (`30_CONSOLE_COMMANDS.md`).

**Sync**: `SynchronizationPlanner` diffs the installed baseline (`InstalledStarterKitTrackingService`) against a newer Blueprint, producing steps shaped identically to installation steps, so `SynchronizationOrchestratorService` can apply them by re-running the EXACT SAME `InstallationSession + InstallationOrchestratorService` machinery — no separate apply-mechanism was built for sync.

## 6. Important Classes

| Class | File |
|---|---|
| `ProjectBuilder` | `src/services/ProjectBuilder.php` |
| `DependencyAnalyzer` | `src/services/DependencyAnalyzer.php` |
| `BlueprintBuilder` | `src/services/BlueprintBuilder.php` |
| `StarterKitBuilder` | `src/services/StarterKitBuilder.php` |
| `InstallationPlanner` | `src/services/installation/InstallationPlanner.php` |
| `InstallationValidator` | `src/services/installation/InstallationValidator.php` |
| `InstallationExecutor` | `src/services/installation/InstallationExecutor.php` |
| Step executors | `src/services/installation/executors/{ComposerExecutor,ContentInstallExecutor,CraftResourceInstallExecutor,FrontendInstallExecutor,NpmExecutor,PluginInstallExecutor,ProjectConfigExecutor}.php` |
| `InstallationStageRunner` | `src/services/installation/InstallationStageRunner.php` |
| `InstallationOrchestratorService` | `src/services/installation/InstallationOrchestratorService.php` |
| `SynchronizationPlanner` | `src/services/synchronization/SynchronizationPlanner.php` |
| `SynchronizationValidator` | `src/services/synchronization/SynchronizationValidator.php` |
| `SynchronizationOrchestratorService` | `src/services/synchronization/SynchronizationOrchestratorService.php` |
| `InstalledStarterKitTrackingService` | `src/services/synchronization/InstalledStarterKitTrackingService.php` |
| `StarterKitCatalogService` | `src/services/installation/StarterKitCatalogService.php` |
| `InstallStarterKitJob` / `SyncStarterKitJob` | `src/jobs/*.php` |

Models: `src/models/installation/{InstallationPlan,InstallationReport,InstallationSession,InstallationStep,ValidationResult}.php`, `src/models/synchronization/{Conflict,SynchronizationPlan,SynchronizationReport,SynchronizationSession,SynchronizationStep,SynchronizationValidationResult}.php`, `src/models/project/{InstallationPlan,Project}.php`.

## 7. Data Model

`site7_install_sessions` (+ a widened data column via a later migration), `site7_installed_starter_kits`, `site7_sync_history`, `site7_sync_sessions`. `InstalledStarterKitRecord` fields: `installedVersion`, `blueprintSnapshot` (JSON), `installedAt`.

## 8. Filesystem Impact

Varies per step executor: `ComposerExecutor` runs Composer operations, `NpmExecutor` runs npm operations, `CraftResourceInstallExecutor` creates native Craft resources (Fields/Sections/etc., `10_CRAFT_CMS_RESOURCE_ARCHITECTURE` — see `04_CRAFT_CMS_INTEGRATION.md`), `ContentInstallExecutor` creates Entries/content, `FrontendInstallExecutor` writes frontend files, `ProjectConfigExecutor` applies Project Config changes.

## 9. Events

`InstallStarterKitJob`/`SyncStarterKitJob` are Craft queue jobs (progress-reportable); no confirmed custom domain events dispatched by this subsystem specifically (distinct from the events in `27_EVENTS_AND_HOOKS.md`, which are mostly import/publishing/commerce).

## 10. Validation and Safety

**Two independently-discovered Craft per-process state-caching bugs** motivate the subprocess architecture — the Composer/Plugins-service caching issue (quoted §5) is the one directly documented; research did not locate a second explicitly-named bug beyond this, though the summary in prior context referenced "two independently-discovered" bugs — treat this as a single confirmed, well-documented instance unless further code evidence surfaces a second.

**Idempotency**: `SynchronizationPlanner` produces steps shaped identically to installation steps SPECIFICALLY so they can be re-executed via the same, already-idempotent install machinery — sync doesn't need its own idempotency guarantees, it inherits them.

**Read-only validators**: both `InstallationValidator` and `SynchronizationValidator` are pure read-only/dry-run by construction — no mutation happens during validation, only during the subsequent execute step.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Subprocess for a stage crashes/times out (>900s) | `InstallationOrchestratorService`'s `STAGE_TIMEOUT_SECONDS` bounds it; session state persists for potential resume |
| Craft version incompatible with the Blueprint's requirements | `SynchronizationValidator` catches this via `Composer\Semver\Semver` version comparison before any apply step |
| Project Config drift detected mid-sync | `SynchronizationValidator` flags it as a read-only pre-check |
| npm unavailable on target environment | `SynchronizationValidator` checks npm availability before proceeding |

## 12. Developer Change Guide

If changing installation stage behavior: modify `InstallationStageRunner`/the relevant `StepExecutorInterface` implementation — never bypass the subprocess boundary by trying to run Composer-then-Plugins-enable in one process, that's the exact bug this architecture fixes.

If changing sync behavior: modify `SynchronizationPlanner`'s diff/classify logic — the apply side (`SynchronizationOrchestratorService`) deliberately reuses install machinery unchanged; don't fork a separate apply path.

## 13. Related Features

`15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md`, `25_DEPENDENCIES_AND_SHARED_RESOURCES.md`, `30_CONSOLE_COMMANDS.md`, `04_CRAFT_CMS_INTEGRATION.md`.

## 14. Known Limitations

Inherits the Website-capture gaps documented in `15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md` §14 (Category/Tag values, blank-title entries, etc.) since this system's input is that capture step's output. This is structurally a SEPARATE three-way-comparison system from `PackageUpdatePlanner` (`19_UPDATE_AND_CONFLICT_HANDLING.md`) — deliberately not merged, per `01_ARCHITECTURE.md`.
