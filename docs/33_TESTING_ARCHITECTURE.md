# 33 — Testing Architecture

## 1. Purpose

Document the test suite's setup, actual pass/fail state as of this documentation pass, and the two known bug classes affecting it.

## 2. What It Does

Codeception 5 + PHPUnit, `unit` suite, `UnitTester` actor. 25 test files under `plugins/site7-studio/tests/` (24 unit + 1 integration — matches §4's count exactly; an earlier "28" here was simply wrong).

## 3. Current Status

**Implemented** (test infrastructure and most tests). **3 files have a hard parse error** (§10) and **3 files have genuine failures** (§10) as of this documentation pass — these are pre-existing, not introduced by the documentation task.

## 4. Architecture

```
codeception.yml (plugin root)
   ↓
tests/unit.suite.yml — actor: UnitTester, modules: [Asserts]
   ↓
tests/_support/UnitTester.php — class UnitTester extends \Codeception\Actor
   { use _generated\UnitTesterActions; }
   ↓
tests/bootstrap.php
   ↓
tests/unit/**/*Test.php (24 files) + tests/integration/CpSubscriberTest.php
   ↓
tests/fixtures/packages/test-hero/*
```

## 5. Execution Flow

`vendor/bin/codecept run unit -c codeception.yml` — parses and runs every file matching the suite. Because 3 files (§10) contain a hard PHP parse error, a **whole-suite invocation currently fails before any test executes** — the only reliable way to run the suite today is per-file or with those 3 files excluded.

## 6. Important Classes

`tests/_support/UnitTester.php`, `tests/_support/_generated/UnitTesterActions.php` (Codeception-generated).

## 7. Data Model

Not applicable — unit suite has no live DB/Craft app; two tests attempt to use Craft/Yii classes anyway and fail as a result (§10).

## 8. Filesystem Impact

Tests are read-only against the plugin source; `tests/fixtures/packages/test-hero/*` is static fixture data, not generated per-run.

## 9. Events

Not applicable.

## 10. Validation and Safety — confirmed suite state

**`protected clone $tester;` typo — hard PHP parse error, confirmed in exactly 3 files** (this is a parse-time failure, not a runtime one — it blocks `codecept run unit` as a whole-suite invocation):
- `tests/unit/services/LibraryServiceTest.php:16`
- `tests/unit/services/ManifestReaderTest.php:13`
- `tests/unit/services/SearchServiceTest.php:13`

**"Class Yii/Craft not found" — confirmed in 3 files** (not 2, correcting an earlier informal count) when run individually:
- `tests/unit/models/packages/PackageManifestTest.php` — `Class "Yii" not found` (2 errors)
- `tests/unit/SettingsTest.php` — `Class "Yii" not found` (1 error)
- `tests/unit/services/synchronization/SynchronizationPlannerTest.php` — `Class "Craft" not found` (2 errors, 7 total failures in the file)

**Genuine assertion-mismatch failures** (not environment errors — real logic/test drift, root causes confirmed against current source):
- `tests/unit/services/import/ResourceImportValidatorTest.php` — `testFlagsUnsupportedFieldsAsWarnings`, `testFlagsAssetsFieldsAsWarning` fail because `ResourceImportValidator::validateImport()` (line 44-50) now reads a pre-computed `$field['classification']` key and no-ops (`continue`) if it's absent; the test still passes raw `'supported' => false` fields with no `'classification'` key, so `$result['warnings']` comes back empty. This is a stale test-fixture shape, not ambiguous drift.
- `tests/unit/services/import/ResourceClassifierServiceTest.php` — `testUnsupportedFieldWithNoSignalIsUnknownResource` fails (expected `'unknown-resource'`, actual `'review-required'`) because of a deliberate rename: `ResourceClassifierService.php:47-49` carries an explicit `@deprecated` comment stating `UNKNOWN_RESOURCE` is kept only so manifests written before this classification pass still read back, and that `classifyField()` never returns it anymore. Not a bug to fix — the test's expected value is the pre-rename constant.

**All other files pass cleanly** when run individually: `CpNavigationRegistryTest`, `CpPermissionRegistryTest`, `ResourceGraphTest`, `InstallationSessionTest`, `SynchronizationSessionTest`, `InstallationPlannerTest`, `InstallationStageRunnerTest`, `InstallationExecutorTest`, `PackageArchiveHelperTest`, `DependencyAnalyzerTest`, `PackageUpdatePlannerTest` (14 tests, per this plugin's own Step 8.2 additions), `RelationFieldSourceResolverTest`, `NavigationScannerTest`, `PlatformConfigServiceTest`, `FrontendToolingScannerTest`, `CraftResourceDiscoveryServiceTest`.

## 11. Failure Scenarios

| Scenario | Cause | Fix category (not applied — documentation only) |
|---|---|---|
| `codecept run unit` fails immediately, no tests execute | Parse error in 3 files (§10) | Fix the typo `protected clone $tester;` → `protected UnitTester $tester;` (or remove the unused property) |
| `PackageManifestTest`/`SettingsTest`/`SynchronizationPlannerTest` fail with "Class Yii/Craft not found" | These tests reference live Craft/Yii classes but the `unit` suite has no bootstrapped Craft app | Needs either a Craft-aware test suite (`functional`/integration-style bootstrap) or refactoring the test to mock the dependency |
| `ResourceImportValidatorTest`/`ResourceClassifierServiceTest` specific assertions fail | See §10 — stale fixture shape (missing `classification` key) and a deliberate `UNKNOWN_RESOURCE`→`review-required` rename, respectively; both root-caused, not mysterious drift | Update the test fixtures/expected values to match current behavior |

## 12. Developer Change Guide

Before trusting `codecept run unit`'s overall pass/fail signal: run it excluding the 3 parse-error files, or fix the typo first — otherwise the whole suite reports failure regardless of any other change's correctness.

## 13. Related Features

None — this document is self-contained testing-infrastructure reference.

## 14. Known Limitations

Full list per §10/§11. These are pre-existing conditions, confirmed by direct suite execution during this documentation research pass, not introduced by any change described in this documentation set.
