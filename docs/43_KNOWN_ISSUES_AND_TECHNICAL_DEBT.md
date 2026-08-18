# 43 — Known Issues and Technical Debt

Consolidated from every feature document's "Known Limitations" section, plus items discovered during this documentation pass. All facts here are re-verified against current code as of this documentation set's creation; none were fixed as part of this documentation-only task.

## Confirmed bugs

1. **`protected clone $tester;` parse error in 3 test files** — hard PHP parse error, blocks whole-suite `codecept run unit` invocation. Files: `tests/unit/services/LibraryServiceTest.php:16`, `tests/unit/services/ManifestReaderTest.php:13`, `tests/unit/services/SearchServiceTest.php:13`. (`33_TESTING_ARCHITECTURE.md`)
2. **3 test files fail with "Class Yii/Craft not found"** when run individually — `PackageManifestTest.php`, `SettingsTest.php`, `SynchronizationPlannerTest.php`. These reference live Craft/Yii classes but the `unit` suite has no bootstrapped Craft app. (`33_TESTING_ARCHITECTURE.md`)
3. **2 test files have genuine assertion-mismatch failures** (not environment errors): `ResourceImportValidatorTest.php` (`testFlagsUnsupportedFieldsAsWarnings`, `testFlagsAssetsFieldsAsWarning`), `ResourceClassifierServiceTest.php` (`testUnsupportedFieldWithNoSignalIsUnknownResource`). (`33_TESTING_ARCHITECTURE.md`)

## Confirmed architectural gaps (not bugs — deliberate stubs/extension points)

4. **Package signing is architecturally prepared but not cryptographically implemented.** `NullPackageSigner` is the only `PackageSignerInterface` implementation; `verify()` always returns `true`. Package authenticity is NOT currently verifiable beyond content-integrity checksums. (`24_LICENSING_AND_COMMERCE.md`)
5. **`PackageInstallEvent`/`PackageEvent` and `PackageSignedEvent` are unused extension points** — declared but no current code path dispatches them. (`27_EVENTS_AND_HOOKS.md`)

## Confirmed leftover artifacts

6. **`templates/site7-components/clientLogos.twig`** — one leftover file predating the current `_blocks/`-based template architecture. Confirmed dead: no current code path reads or writes it. Harmless but not cleaned up (documentation-only task scope). (`13_TEMPLATE_ARCHITECTURE.md`)

## Confirmed simplifying assumptions

7. **Template install mapping assumes exactly one Matrix block per Section package** — `CraftResourceService`'s own comment describes this as an explicit "MVP: assume first block handle" simplification. (`13_TEMPLATE_ARCHITECTURE.md`)
8. **`CraftSectionImportService` does not dispatch `ResourceImportedEvent` directly** — it relies entirely on the per-Entry-Type delegate (`MatrixEntryTypeImportService`) dispatching it, which happens to produce the same effective backup behavior but is a docblock/behavior mismatch worth noting for anyone reading `CraftSectionImportService`'s own comments expecting a direct dispatch. (`26_BACKUP_AND_RECOVERY.md`)

## Confirmed real-world capture gaps (Website/Starter Kit import path)

9. Per the plugin's own `VALIDATION-REPORT-FULL-PIPELINE.md` (an older document; facts re-verified/summarized here, not depended upon as ground truth per this documentation set's own no-dependency rule): `TemplateGeneratorService::generateFromEntry()` ("Save as Template") only works for pages authored through Site7 Studio's own visual builder — 0 of 885 real traditionally-authored entries qualified in that historical test. Category/Tag field VALUES (not group settings) are never captured/re-linked. Blank-title entries fail capture with an unhelpful error. Some of these were reportedly addressed in later work not independently re-verified during this documentation pass — treat as unconfirmed until re-tested. (`15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md`)

## Absence of certain safety mechanisms (not necessarily bugs, but worth tracking)

10. No explicit locking around concurrent version creation for the same package, AND `(packageId, version)` uniqueness is application-level only — there is no DB unique index backing it up. (`17_PACKAGE_VERSIONING.md`)
11. No cross-package `targetPath` collision detection for owned files — two different packages could declare ownership of the same target path with no guard. (`21_FRONTEND_FILE_OWNERSHIP.md`)
12. No index for querying `site7_installed_files` baselines across packages by `targetPath` alone (only the composite unique index) — not currently needed by any code path, but would matter if a cross-package ownership audit feature were ever built (see item 11). (`16_INSTALLED_FILE_BASELINE.md`)

## Confirmed architectural gaps found during this documentation-validation pass

15. **`PageUpdateService` (Page-package Sync From Source) does not conform to the guarantees `SectionUpdateService` provides.** No change-detection gate (writes + creates a version on every call, even when nothing changed), bypasses `VersionManagerService` entirely (no semver bump, no `archivePath` — permanently blocking rollback for these version rows — and a non-standard checksum scheme). Repeated syncing of an unchanged Page produces duplicate `site7_package_versions` rows, since `(packageId, version)` has no DB-level uniqueness (item 10 below) and this path skips the application-level dedup check other writers use. (`18_SYNC_FROM_SOURCE.md` §5a, `17_PACKAGE_VERSIONING.md`)
16. **RESOLVED.** `PackageActionController`'s `install`/`enable`/`disable`/`remove`/`detach` actions now require the `managePackages` permission (the same permission already used by `CommerceController` for its own package install/remove actions), in addition to `requirePostRequest()`. `detach` keeps its pre-existing Dev-Mode gate as an additional layer on top of the permission check. `delete` was intentionally left out of scope for this fix and remains Dev-Mode-gated only. (`28_CONTROLLERS_AND_ROUTES.md` §10, `31_SECURITY_AND_VALIDATION.md`) — **Not yet independently live-verified**: this Craft install is capped at a single user (edition/license limit confirmed live — `ddev craft users/create` fails with "The maximum number of users has already been reached"), so authorized-vs-unauthorized click-through testing could not be performed in this environment. Needs re-verification on a multi-user-capable install before being treated as fully proven in production.
17. **The Starter Kit Build phase (`StarterKitBuilder`) has no CP entry point** — reachable only via `php craft site7-studio/make/starter-kit`. Two other, unrelated CP-reachable "Starter Kit" flows exist (`PackageAuthoringController::actionSaveStarterKit`, `StarterKitGeneratorController`) that don't produce a `blueprint.json` and aren't part of this system. (`32_STARTER_KIT_SYSTEM.md` §14)

## Confirmed bugs found and fixed after this documentation set's creation

18. **RESOLVED (2026-08-18).** Clicking the "Add Section" button injected into a Matrix field could silently trigger Craft's own native single-entry-type "instant add" action in the background, creating an unwanted block independent of the Site7 Content Browser modal (most visible when the field's View Mode is "Blocks" and it allows exactly one Entry Type). Root cause: the injected button shared Craft's generic `btn` class and lived inside the same `.buttons` container Craft's native field JS scopes its own add-button selector to. Fixed by moving the injected buttons to be a DOM sibling of `.buttons` (not a child) and dropping the `btn` class. (`44_CONTENT_BROWSER_MATRIX_INJECTION.md` §11, `39_TROUBLESHOOTING.md` §13)
19. **RESOLVED (2026-08-18, same day as item 18).** Site7's "Add Section"/"Insert Pattern" buttons could be injected into, and a nested field's own native control hidden on, a Matrix-type field nested inside a site7Components block's own entries (e.g. a "CTA Banner" block's own "CTA Button" field). Root cause: `injectButton()` resolved the target field's own `.buttons` container with `.find('.buttons').first()` (an unscoped descendant search that can match a nested field's `.buttons` instead, since Craft renders a field's blocks/entries before its own `.buttons`, sorting the nested one first in DOM order), and the CSS hiding native controls used a descendant combinator instead of a child combinator. Fixed by using `.children()` and child-combinator (`>`) CSS scoping throughout. (`44_CONTENT_BROWSER_MATRIX_INJECTION.md` §11B, `39_TROUBLESHOOTING.md` §14)

## Documentation gaps identified during this pass

13. This documentation set does not independently re-verify every claim in the older `VALIDATION-REPORT-FULL-PIPELINE.md`-sourced gap list (item 9) against current code — only the facts explicitly re-derived and cited above are confirmed current; treat unlabeled historical claims as unconfirmed.
14. `PHASE-6-INSTALLATION-ORCHESTRATION.md` is referenced by docblocks inside `InstallationOrchestratorService` but was not located/read during this documentation pass — its "proven findings" are summarized secondhand from the docblock quote in `32_STARTER_KIT_SYSTEM.md` §10, not independently verified against the original document.
