# 30 — Console Commands

## 1. Purpose

Document every console controller — used both by developers directly and, critically, as the internal subprocess mechanism the Starter Kit installer relies on.

## 2. What It Does

`src/console/controllers/*.php`, each extending `\craft\console\Controller`, exposing `actionX()` methods reachable via `php craft site7-studio/<controller>/<action>`.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
CLI: php craft site7-studio/{controller}/{action} [args] [--options]
   ↓
craft\console\Controller subclasses under src/console/controllers/
   ↓
Most delegate directly into the same services the CP controllers use
   ↓
SPECIAL CASE: InstallController::actionRunStage($sessionUid) is not meant
   for direct human use — it's the exact subprocess entry point
   InstallationOrchestratorService spawns for every installation stage
   (32_STARTER_KIT_SYSTEM.md)
```

## 5. Execution Flow / Command Inventory

| Controller | File | Actions |
|---|---|---|
| `ClearController` | `src/console/controllers/ClearController.php` | actionSettings() |
| `InstallController` | `src/console/controllers/InstallController.php` | actionList(), actionValidate(string $handle), actionRun(string $handle) (supports `--dryRun`/`-d`), actionRunStage(string $sessionUid) — subprocess-only entry point, never invoked directly by a user |
| `MakeController` | `src/console/controllers/MakeController.php` | actionRelinkMatrix(string $handle), actionSetupMatrixField(), actionStarterKit(string $name), actionPackage(string $handle) |
| `PackageController` | `src/console/controllers/PackageController.php` | actionSync() |
| `UpdateController` | `src/console/controllers/UpdateController.php` | actionList(), actionPlan(string $handle), actionRun(string $handle, string $removals = ''), actionApplyRemovals(string $sessionUid) |

## 6. Important Classes

See table above. `InstallationOrchestratorService::runStageSubprocess()` (`src/services/installation/InstallationOrchestratorService.php`) is the caller that spawns `InstallController::actionRunStage` as a real OS subprocess via `Symfony\Component\Process\Process`.

## 7. Data Model

Console commands read/write the same tables as their CP-triggered equivalents (`site7_install_sessions`, `site7_sync_sessions`, etc.) — no console-specific tables.

## 8. Filesystem Impact

Same as the underlying services each command delegates to.

## 9. Events

Same as the underlying services — console commands are a thin CLI wrapper, not a separate event source.

## 10. Validation and Safety

**`actionRunStage` is not a general-purpose command** — restated from its own docblock: "the subprocess `InstallationOrchestratorService` spawns fresh for every stage... never invoked directly by a user." Running it manually against an arbitrary session uid outside the orchestrator's control flow is unsupported.

**`--dryRun`/`-d` on `InstallController::actionRun`**: lets a developer preview a Starter Kit install plan without executing it — read-only.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| `actionRunStage` called with an invalid/nonexistent session uid | Would fail loading the session — no specific graceful path documented |
| `actionRun` interrupted mid-install (process killed) | Session state persists in `site7_install_sessions`; re-running `actionRun` for the same handle is expected to resume from session state (subprocess-per-stage design implies resumability, per `32_STARTER_KIT_SYSTEM.md`) |

## 12. Developer Change Guide

If adding a new console command: follow the existing pattern of thin delegation into an existing service — do not put business logic directly in a console controller action.

## 13. Related Features

`32_STARTER_KIT_SYSTEM.md`, `28_CONTROLLERS_AND_ROUTES.md`.

## 14. Known Limitations

None confirmed beyond the subprocess-only nature of `actionRunStage` (not a limitation, a deliberate design constraint).
