# 40 — Extension Guide

How to safely add new capabilities without duplicating existing systems.

## Adding a new package type
Extend the type-specific branches in `PackageManagerService::installPackage()`/manifest handling, following the existing `section`/`template`/`pattern`/`starter-kit`/`theme` pattern (`06_PACKAGE_ARCHITECTURE.md`). Do not create a parallel install method.

## Adding a new owned-file type (beyond CSS/JS/config/asset)
Add the type label to `ownedFiles` entries — the install/baseline/sync/update/rollback machinery is already type-agnostic (`21_FRONTEND_FILE_OWNERSHIP.md` §12). No new service needed.

## Adding a new marketplace repository backend
Implement `MarketplaceRepositoryInterface` and register it in `MarketplaceService::init()` (`23_MARKETPLACE_ARCHITECTURE.md` §12).

## Adding a new commerce-gated feature
Use `FeatureGateService` — do not hand-roll a new entitlement check (`24_LICENSING_AND_COMMERCE.md` §12).

## Implementing real package signing
Replace `NullPackageSigner` with a real `PackageSignerInterface` implementation; `PackagePublisherService` already calls the interface, not the concrete class (`24_LICENSING_AND_COMMERCE.md` §12).

## Adding a new CP nav item or permission
Add a listener to `RegisterNavigationEvent`/`RegisterPermissionsEvent` (or extend `CpSubscriber`) — never hard-code into `CpNavigationRegistry`/`CpPermissionRegistry` (`29_CP_UI_ARCHITECTURE.md` §12).

## Adding a new domain event
Extend `BaseEvent`, dispatch via `EventDispatcher::dispatch()`, prefer a subscriber over ad-hoc `Event::on()` calls (`27_EVENTS_AND_HOOKS.md` §12).

## Adding a new console command
Thin delegation into an existing service — no business logic in the console controller itself (`30_CONSOLE_COMMANDS.md` §12).

## Adding a new Starter Kit installation stage/step type
Implement `StepExecutorInterface` and register it with `InstallationExecutor` — never bypass the subprocess boundary (`32_STARTER_KIT_SYSTEM.md` §12).

## Adding a new file type to the three-way update/rollback system
Requires (a) a baseline recorded via `InstalledFileBaselineService::record()` at install time and (b) an entry in `PackageUpdatePlanner::resolveArchiveEntryName()`'s resolution logic. Never build a parallel comparison system (`19_UPDATE_AND_CONFLICT_HANDLING.md` §12).

## The one rule that supersedes all of the above
Before writing any new service: search this documentation set's Service Reference (`38_SERVICE_REFERENCE.md`) and the actual `src/services/` tree for something that already does what you need. This plugin's own development history (`34_DEVELOPMENT_WORKFLOW.md`) shows every real feature step reused existing infrastructure rather than duplicating it — that pattern is the extension model this plugin is built around.
