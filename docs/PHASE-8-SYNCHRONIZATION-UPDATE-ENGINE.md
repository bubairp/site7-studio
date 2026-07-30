# Phase 8 — Synchronization & Update Engine

Status: **Implemented, awaiting architectural review before Marketplace integration.** Per `WEBSITE-STARTER-KIT-SYSTEM.md` Phase 8 - lifecycle management for an already-installed Starter Kit. Verified against a genuinely fresh, throwaway Craft 5 + DDEV project provisioned solely for this phase and torn down afterward, not the shared rp-craft dev site.

## Architecture: diff/plan strictly separate from execution, execution reuses Phase 6/7 wholesale

```
Installed baseline (site7_installed_starter_kits: version + Blueprint snapshot at install/last-sync time)
  + newer Blueprint (packages/<handle>/blueprint.json, already updated in place)
      │
      ▼
SynchronizationPlanner::plan()        read-only: diffs old vs new Blueprint, checks LIVE Craft state for drift
      │
      ▼
SynchronizationPlan  {steps (create/update - safe), removals (opt-in), conflicts (manual review only),
                       pluginChanges, frontendChanges, dependencyChanges, projectConfigChanges}
      │
      ▼
SynchronizationValidator::validateSynchronization()   read-only compatibility checks
      │
      ▼
SynchronizationSession (persisted)  - the Update Wizard's counterpart to Phase 7's InstallationSession
      │  (confirmed - user opted specific removals in, or none)
      ▼
SynchronizationOrchestratorService::execute()
      │
      ├─► builds an executable Blueprint (new Blueprint minus conflicted resources)
      ├─► creates a real InstallationSession from it, drives it via the EXISTING,
      │   UNCHANGED InstallationOrchestratorService/InstallationStageRunner/
      │   InstallationExecutor (composer → install → project-config, same three
      │   process-boundary stages Phase 7 already established)
      ├─► applies confirmed removals via a dedicated subprocess (ResourceRemovalExecutor
      │   + a reused ProjectConfigExecutor rebuild - the one genuinely new execution
      │   capability this phase adds)
      └─► records SynchronizationHistoryService + advances InstalledStarterKitTrackingService
      │
      ▼
SynchronizationReport
```

No file in this phase reimplements Phase 6's Composer/plugin/Craft-resource/npm/Project-Config execution logic. The only genuinely new *execution* code is opt-in resource removal (`ResourceRemovalExecutor`) - everything else create/update related is applied by literally re-running Phase 7's install machinery against the newer Blueprint, which is already idempotent by design (Phase 6's own "Idempotency" live-testing finding).

## SynchronizationPlanner - the diff engine

`src/services/synchronization/SynchronizationPlanner.php`. For each resource kind Phase 2 captures (Category Groups, Tag Groups, Asset Volumes, Craft Sections), compares the previously-installed Blueprint's captured definition against the new one, and - critically - against **live Craft state** (via the existing `scanning/` scanners, read-only) before ever proposing an auto-apply:

- **New in the new Blueprint, absent from the old one** → a `create` step.
- **Present in both, definition changed** → checked against live state first:
  - Live still matches the *old* Blueprint's definition (untouched since install) → a safe `update` step.
  - Live has drifted from the old Blueprint (edited directly in the CP, or otherwise, since install) → a `Conflict::TYPE_LOCALLY_MODIFIED` - **never auto-applied**, regardless of what the new Blueprint wants.
- **Present in the old Blueprint, absent from the new one** → the new version no longer wants this resource:
  - Live still matches the old Blueprint → an opt-in `removal` (nothing is removed until a user explicitly confirms that specific resource key).
  - Live has drifted → a conflict instead of a removal, since silently deleting a locally-modified resource is exactly the kind of silent data loss this phase exists to prevent.

Plugins are diffed by handle: newly-required plugins flow through Phase 6's existing composer/plugin-install machinery unchanged (added naturally when the executable Blueprint is built). A version-constraint change on an *already*-required plugin is surfaced as a `Conflict::TYPE_DEPENDENCY` rather than silently ignored, since Phase 6's `InstallationPlanner` only ever adds a composer step for a package not yet required at all - it has no "bump an existing requirement" primitive, so pretending this could be auto-applied would be dishonest. npm package/config-file changes are diffed similarly (added/updated npm packages flow through naturally via Phase 6's existing `NpmExecutor`/`FrontendInstallExecutor`; removed npm packages are reported only, matching Phase 6's own "never delete" convention).

**Explicit, stated-up-front scope boundary**: page content (`resources.pages`) is never diffed field-by-field. Comparing captured content safely - without silently discarding a local edit to a page - is real work this phase doesn't attempt; a changed page list is only ever an informational note in `dependencyChanges`, never an auto-applied step. This is why every Content step in this phase's own live testing correctly stayed in the "requires manual review" territory rather than being silently re-run.

## Two critical fixes found only by live testing

### 1. The raw new Blueprint must never reach the reused install machinery unfiltered

Phase 6's `InstallationPlanner` has no concept of "this resource is in conflict" - it just re-derives steps from whatever Blueprint it's given. Live-testing this phase's very first non-dry-run caught it directly: a category group flagged as a `locally-modified` conflict was still silently renamed back to the new Blueprint's value, because `SynchronizationOrchestratorService` was handing the *raw* new Blueprint straight to a fresh `InstallationSession`. Fixed by `buildExecutableBlueprint()`, which strips every conflicted resource out of the Blueprint before it's ever passed to `InstallationSessionService::create()` - the reused Phase 6/7 machinery never even sees it. Re-verified live afterward: the conflicted resource stayed at its locally-modified value across the run.

### 2. Opt-in removal needs its own process boundary too - a third instance of Phase 6/7's own finding

Live-testing the opt-in removal path immediately hit `"The loaded project config is out-of-date"` from Craft's own Categories service. Root cause: `SynchronizationOrchestratorService::execute()` had just called `InstallationOrchestratorService::runToCompletion()`, whose subprocesses rebuilt Project Config *on disk*; the calling process's own in-memory `Craft::$app` still held the project-config snapshot from when *it* booted, before those subprocesses ran. Attempting a removal in that same process was operating on stale state - exactly the same "state written earlier in this process isn't reliably visible to something else in this same process" bug class as Phase 6's composer/plugin-install finding and Phase 7's plugin-install/project-config finding, just a third independently-discovered instance of it. Fixed the same way both previous instances were: removal now runs in its own freshly-spawned subprocess (`site7-studio/update/apply-removals <uid>`, spawned by `SynchronizationOrchestratorService` the same way `InstallationOrchestratorService` spawns its own stage subprocesses), followed by one more `ProjectConfigExecutor` rebuild (reused directly, not reimplemented). Re-verified live: the confirmed removal succeeded and Project Config was correctly reconciled afterward.

### A third, related correctness fix: what the recorded baseline actually reflects

A `SynchronizationReport.success` reflecting a strict "every single step succeeded" (matching Phase 6's own `InstallationReport.success` semantics) meant the tracked baseline would *never* advance for a real Blueprint whose Content step legitimately isn't auto-applied by this phase (see the scope boundary above) - every later sync would then re-diff against a permanently stale snapshot. Mirroring the same leniency `InstallController` already applies for Phase 7's own baseline recording (`fatalError === null`, not `status === STATUS_COMPLETED`), the baseline advances whenever execution genuinely ran, independent of the report's own strict pass/fail. But advancing the baseline to the *raw* new Blueprint verbatim would be wrong too: a conflicted resource was never actually touched, and an unconfirmed removal is still sitting there. `buildAppliedBlueprintSnapshot()` reconstructs what was *actually* applied - the new Blueprint's values, except conflicted resources (kept at their old captured value, so the conflict correctly persists into the next sync's diff) and unconfirmed removals (kept, not dropped). Verified live: re-planning immediately after a sync with one unresolved conflict correctly showed zero new steps and the same conflict still pending, not a silently-forgotten one.

## Version tracking

`InstalledStarterKitTrackingService` (`site7_installed_starter_kits` table) - one row per Starter Kit handle: `installedVersion`, a full `blueprintSnapshot` (the baseline every future sync diffs against), `installedAt`, `lastSyncedAt`. Recorded by Phase 7's own entry points (`InstallController::actionRun`, `InstallStarterKitJob`) immediately after a fresh install - a small, additive hook into otherwise-unchanged Phase 7 files, since Phase 8 cannot function without *something* recording what got installed. `SynchronizationHistoryService` (`site7_sync_history`) records one row per synchronization attempt, successful or not, surfaced in the Update Wizard's own Summary step.

## Update Wizard

Mirrors the Fresh-Install Setup Wizard's exact shape: `UpdateWizardController` (Step 1 lists available updates via `UpdateCatalogService`, comparing the tracked baseline's version against whatever's currently in `packages/<handle>/manifest.json`; Steps 2-3 build and preview a `SynchronizationPlan` synchronously, since planning/validating never mutates anything; Step 4 pushes `SyncStarterKitJob` onto Craft's queue and returns immediately; Step 5 is a server-rendered summary including this kit's own update history) + `UpdateController` (console parity) + `SyncStarterKitJob` (queue parity with `InstallStarterKitJob`) + `templates/update-wizard/*` + `resources/js/update-wizard.js`, following `install-wizard.js`'s exact conventions (`Craft.getActionUrl()`/`fetch()`, polling, redirect-to-summary-on-done).

The Preview step lets a user opt specific dropped resources into removal via checkboxes (unchecked = left in place, the safe default) - `confirmedRemovalKeys` is the only removal-related state the wizard ever writes.

## Tests

- `tests/unit/models/synchronization/SynchronizationSessionTest.php` - `isDone()` and the `toArray()`/`fromArray()` round trip (the exact shape `SynchronizationSessionService` persists).
- `tests/unit/services/synchronization/SynchronizationPlannerTest.php` - every branch of the diff logic using fake scanners (duck-typed - `SynchronizationPlanner`'s scanner properties are deliberately untyped rather than the concrete scanner classes, the same Yii-config-constructible testability pattern `InstallationExecutor`'s own `$executorsByType` already established): new→create, unchanged→nothing, changed-with-no-drift→update, changed-with-drift→conflict-not-update, dropped-unmodified→opt-in-removal, dropped-with-drift→conflict-not-removal, plugin added/updated/removed classification, and a missing-`packageHandle` Blueprint producing an error with zero steps.

This host still has no PHPUnit/Codeception binary (same as Phases 6-7), so every assertion above was additionally hand-verified via direct PHP scripts against the plugin's real autoloader - and, since `SynchronizationPlanner::plan()` always calls `Craft::$app->getProjectConfig()->areChangesPending()`, that hand-verification specifically ran as a temporary console command against a live Craft app (removed afterward) rather than a bare script, unlike Phase 7's fully Craft-independent hand-verification. This is exactly how the strict-scanner-property-typing bug below was actually caught.

## A real bug caught only by hand-verifying against a live app

`SynchronizationPlanner`'s four scanner properties were originally typed against their concrete classes (`?CategoryGroupScanner`, etc.) - correct for production, but it meant `new SynchronizationPlanner(['categoryGroupScanner' => $fakeScanner])` fatally errored (`TypeError: Cannot assign class@anonymous to property ... of type ?CategoryGroupScanner`) the moment a test tried to inject a duck-typed fake, since none of the four scanner classes implement a shared interface narrow enough to type-hint against instead. Loosened to untyped properties, mirroring `InstallationExecutor::$executorsByType`'s own precedent for the same reason.

## Live verification

Against a genuinely fresh, throwaway Craft 5.10 + DDEV project, plugin linked via a Composer path repository (plugin source copied into the fresh project's own tree, same as Phase 7). Verified with a hand-authored, schema-faithful two-version test Starter Kit (`phase8-test-kit`, versions 1.0.0 → 1.1.0 → 1.2.0): three Category Groups (one kept-and-renamed, one dropped, one deliberately locally-modified before syncing to force a conflict), one newly-added Category Group, and one newly-required plugin (`craftcms/ckeditor`, matching Phase 6/7's own proven choice).

1. **Version tracking baseline** - recorded correctly after v1.0.0's install despite its Content step failing (this test kit intentionally has no packaged content, to isolate this phase's own diff/orchestration logic from Phase 5/6's already-proven capture pipeline) - confirmed by relaxing the recording condition to match Phase 7's own `fatalError === null` leniency, the same fix applied there.
2. **Diff correctness** - `update/plan` correctly classified: an unchanged-but-renamed Category Group as `update`, a new one as `create`, a dropped-but-unmodified one as an opt-in `removal`, and the deliberately locally-modified one as a `Conflict` - never silently overwritten.
3. **Compatibility checks** - correctly reported "not yet installed - will be required and installed" for the new plugin requirement, and "no pending Project Config changes."
4. **Real synchronization** - `craftcms/ckeditor` was actually required (composer) and installed+enabled (plugin-install) as two genuinely separate subprocesses (Phase 6/7's process boundary, reused correctly); the renamed Category Group was actually updated; the new one was actually created; the conflicted one was correctly left untouched (confirmed via direct DB query - its name never changed); the confirmed removal actually happened (a real Craft soft-delete, confirmed via `dateDeleted`); Content correctly failed with a clear message and did not block Project Config from rebuilding.
5. **Idempotent re-sync** - re-planning immediately afterward against the now-current baseline showed zero auto-appliable steps and the same single conflict still pending (not silently forgotten, and not re-proposing the already-removed resource) - the `buildAppliedBlueprintSnapshot()` fix, confirmed working.
6. **Update history** - `site7_sync_history` correctly recorded the run (`1.0.0 → 1.1.0`, status `failed` - honest, since Content did fail - even though the baseline still correctly advanced).
7. **CP wizard, end to end, in a real browser** - bumped the test kit to 1.2.0 with a trivial rename, logged into the fresh site's CP, drove Steps 1-4 (Select Update → Dry Run compatibility check → Preview showing the update/conflict → Execute), confirmed the queued `SyncStarterKitJob` ran via `craft queue/run`, and confirmed the wizard's own polling redirected to a server-rendered Step 5 Summary showing the "Success" badge, "This was a Dry Run" notice, the persisted conflict, and the Update History table with the prior real run.

## An unrelated incident during this phase's own live testing

While iterating on a temporary hand-verification console command against the shared rp-craft dev site (not a fresh DDEV project), files belonging to two pre-existing, unrelated packages (`packages/company/*`, `packages/page-home/*`) were found deleted from the working tree partway through this session - discovered only at final cleanup, via `git status`. These were restored immediately via `git checkout -- packages/company packages/page-home` (both tracked, unmodified files, so this was a clean, lossless restore) before any commit. The root cause was not conclusively identified before this write-up - the leading suspects are the several `WebsiteImportService`/`ProjectBuilder` calls made directly against rp-craft's live content earlier in this session (some of which threw exceptions partway through - see the abandoned scratch-test entries in this session's own history), since no command in this phase's own final, retained code path touches `packages/company` or `packages/page-home` at all. Flagged here explicitly rather than silently fixed, since a plugin capable of deleting unrelated package files as a side effect of an unrelated operation is a real risk worth the architectural review's attention - independent of whether this specific phase's code caused it.

## Scope boundaries carried forward

No Marketplace or Cloud update UI (this wizard is designed to be reusable by both, per the phase's own UX requirements, but neither is wired up yet). No page/content-level diffing or auto-update - always manual-review-only, stated up front rather than discovered by a user. No automatic `composer update` for an already-required plugin's changed version constraint - surfaced as a dependency conflict for manual resolution, since Phase 6 has no such primitive. Removal is deliberately narrow in scope (Category/Tag Groups only, via `ResourceRemovalExecutor`) - Craft Sections and Asset Volumes are never auto-removed, mirroring Phase 6's own conservative boundary against auto-*creating* either of those in full. The pre-existing fresh-install migration gap documented in Phase 7 is unchanged (still present, still out of this phase's scope).
