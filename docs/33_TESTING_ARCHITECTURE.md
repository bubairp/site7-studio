# 33 — Testing Architecture

## 1. Purpose

Document the test suite's setup, actual pass/fail state as of this documentation pass, and the two known bug classes affecting it.

## 2. What It Does

Codeception 5 + PHPUnit, `unit` suite, `UnitTester` actor. 25 test files under `plugins/site7-studio/tests/` (24 unit + 1 integration — matches §4's count exactly; an earlier "28" here was simply wrong).

## 3. Current Status

**Implemented.** The whole `unit` suite runs green in one invocation (153 tests as of 2026-10-02) — the parse errors, missing Yii/Craft classes and stale assertions recorded in §10 are fixed.

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

`vendor/bin/codecept run unit -c codeception.yml` — parses and runs every file matching the suite (from the plugin root; inside this project that's `../../vendor/bin/codecept` in the DDEV web container). `codeception.yml`'s `bootstrap: bootstrap.php` loads `tests/bootstrap.php`, which finds Composer's autoloader (the plugin's own `vendor/` or the host project's) and loads the `Yii`/`Craft` class files, which Composer doesn't autoload. `Craft::$app` stays null — a test that reaches it stubs it itself (see `SynchronizationPlannerTest::_before()`).

## 6. Important Classes

`tests/_support/UnitTester.php`, `tests/_support/_generated/UnitTesterActions.php` (Codeception-generated).

## 7. Data Model

Not applicable — unit suite has no live DB/Craft app; two tests attempt to use Craft/Yii classes anyway and fail as a result (§10).

## 8. Filesystem Impact

Tests are read-only against the plugin source; `tests/fixtures/packages/test-hero/*` is static fixture data, not generated per-run.

## 9. Events

Not applicable.

## 10. Validation and Safety — confirmed suite state

**All of the following was fixed on 2026-10-02** (kept as a record of what was wrong):
- the 3 parse errors → `protected \UnitTester $tester;`
- the 3 "Class Yii/Craft not found" files, plus `ManifestReaderTest::testReadInvalidManifest` (`Craft::error()`, only reachable once its parse error was gone) → `tests/bootstrap.php` wired into `codeception.yml` (§5), and a `Craft::$app` stub for `SynchronizationPlannerTest`
- the assertion mismatches → tests updated to current behavior. Fixing `ResourceImportValidatorTest` also exposed a **real bug**: `validateImport()` warned on the deprecated `UNKNOWN_RESOURCE` but had no case for its replacement `REVIEW_REQUIRED` (nor `EXTERNAL_DEPENDENCY`), so fields needing manual review produced no warning at all. Both are now warned on; the test runs fields through the real classifier, so it guards that.

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

| Scenario | Cause | Fix |
|---|---|---|
| "Class Yii/Craft not found" | `tests/bootstrap.php` not loaded (run without `-c codeception.yml`, or the `bootstrap:` key removed) | Run with `-c codeception.yml` from the plugin root |
| "Call to a member function ... on null" from `Craft::$app` | Code under test reaches the live app; the unit suite has none | Stub only the calls it makes in the test's `_before()`, reset `Craft::$app = null` in `_after()` |

## 12. Developer Change Guide

Run the whole suite (`codecept run unit -c codeception.yml`) before and after a change — it's green, so any failure is new.

## 13. Related Features

None — this document is self-contained testing-infrastructure reference.

## 14. Known Limitations

Full list per §10/§11. These are pre-existing conditions, confirmed by direct suite execution during this documentation research pass, not introduced by any change described in this documentation set.
