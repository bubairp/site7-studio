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
16. **`PackageActionController`'s destructive package actions (`install`/`enable`/`disable`/`remove`) have no `requirePermission()` gate and no Dev-Mode gate** — any CP user with general CP access can invoke them. (`delete`/`detach` on the same controller are at least Dev-Mode-gated.) (`28_CONTROLLERS_AND_ROUTES.md` §10, `31_SECURITY_AND_VALIDATION.md`)
17. **The Starter Kit Build phase (`StarterKitBuilder`) has no CP entry point** — reachable only via `php craft site7-studio/make/starter-kit`. Two other, unrelated CP-reachable "Starter Kit" flows exist (`PackageAuthoringController::actionSaveStarterKit`, `StarterKitGeneratorController`) that don't produce a `blueprint.json` and aren't part of this system. (`32_STARTER_KIT_SYSTEM.md` §14)

## Documentation gaps identified during this pass

13. This documentation set does not independently re-verify every claim in the older `VALIDATION-REPORT-FULL-PIPELINE.md`-sourced gap list (item 9) against current code — only the facts explicitly re-derived and cited above are confirmed current; treat unlabeled historical claims as unconfirmed.
14. `PHASE-6-INSTALLATION-ORCHESTRATION.md` is referenced by docblocks inside `InstallationOrchestratorService` but was not located/read during this documentation pass — its "proven findings" are summarized secondhand from the docblock quote in `32_STARTER_KIT_SYSTEM.md` §10, not independently verified against the original document.
