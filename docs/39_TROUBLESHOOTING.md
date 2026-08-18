# 39 — Troubleshooting Guide

Twelve common symptoms. Each entry lists likely cause, where to inspect, and SAFE debugging steps. **Do not apply destructive fixes (deleting rows, force-overwriting files) without understanding the risk described below.**

---

### 1. "Package installed but template does not render"
**Likely cause**: the block handle doesn't match what `matrix-container.twig` dispatches on, or the file was never actually copied (content-compare guard silently skipped it because a DIFFERENT file already existed at that path).
**Inspect**: `templates/_blocks/{handle}.twig` existence; `site7_installed_files` row for the package; `CraftResourceService::generateResources()` return value (`installedTemplate` key — null means no copy happened).
**Safe steps**: confirm the Matrix block's Entry Type handle matches the target filename; check CP for install warnings.

### 2. "Rollback does not restore a file"
**Likely cause**: the file was classified `RESULT_LOCAL_MODIFICATION` or `RESULT_CONFLICT` and deliberately skipped (`19_UPDATE_AND_CONFLICT_HANDLING.md`) — this is a SAFETY FEATURE, not a bug.
**Inspect**: rollback's reported skip/conflict list; compare live file checksum against `site7_installed_files.checksum` and the target version's archived content.
**Safe steps**: manually review the diff before deciding whether to force-apply — there is no "force rollback" flag; manual file replacement is the only way, and it bypasses all safety checks, so back up first.

### 3. "Sync creates an unexpected version"
**Likely cause**: `SectionUpdateService::diff()` detected a real difference — check ALL three diff dimensions (fields, Twig, owned files), not just the one you expected changed.
**Inspect**: `diffTwig()`/`detectFields()`/`diffOwnedFiles()` results; live source vs package's stored copy.
**Safe steps**: compare checksums manually via `PackageArchiveHelper::computeFileChecksum()` before assuming the diff is wrong.

### 4. "Frontend CSS is not installed"
**Likely cause**: the file was never selected as an owned file at import/capture time (`21_FRONTEND_FILE_OWNERSHIP.md`) — ownership is explicit, never automatic.
**Inspect**: `manifest.json`'s `ownedFiles` array for the package.
**Safe steps**: if missing, re-run "Import Existing Website/Section" and explicitly select the file as an owned file; do not hand-copy the file, which would bypass baseline tracking.

### 5. "Update Available badge shows for an unchanged package"
**Likely cause**: `EntryTypeSourceHasher`/`diff()` is structural, not cosmetic — a change to something the hash includes (even if visually unimportant) will trigger this; conversely a `name` rename alone should NOT trigger it (deliberately excluded).
**Inspect**: `EntryTypeSourceHasher::computeHash()`'s included fields.
**Safe steps**: diff the actual field layout/Twig content manually before assuming this is a false positive.

### 6. "Duplicate package created from the same Entry Type"
**Likely cause**: should be impossible — `SectionImportSourceRepository::findBySourceUid()` guards this. If it happened, check whether the Entry Type's `uid` changed (e.g. it was deleted and recreated rather than truly the same resource).
**Inspect**: `site7_section_import_sources` rows for that `sourceUid`.
**Safe steps**: do not delete either package without confirming which one has real content/installed files depending on it (`12_PACKAGE_UNINSTALLATION.md`'s usage checks).

### 7. "Deleting a package didn't remove its template file"
**Likely cause**: correct behavior if the installed file no longer byte-matches the package's own `template.twig` (developer modified it) — `deletePackage()` deliberately leaves modified files in place (`12_PACKAGE_UNINSTALLATION.md` §10).
**Inspect**: the warnings array returned by `deletePackage()`.
**Safe steps**: manually review and delete the orphaned file only after confirming it's truly unwanted.

### 8. "Version number collided after a rollback"
**Likely cause**: should be impossible post-Step-7-fix — if seen, check whether `VersionManagerService::createVersion() (internally resolveBumpBaseVersion())` is being bypassed by a code path that reads `manifest.version` directly instead of calling the service (`17_PACKAGE_VERSIONING.md` §10).
**Inspect**: all `site7_package_versions` rows for the package, sorted by version, vs. the manifest's current version.
**Safe steps**: never manually edit `manifest.json`'s version field to "fix" this — find and fix the code path bypassing `VersionManagerService`.

### 9. "Shared Resource shows as missing but install succeeded anyway"
**Likely cause**: expected behavior — Shared Resource resolution never blocks install (`25_DEPENDENCIES_AND_SHARED_RESOURCES.md` §10).
**Inspect**: `getLastInstallWarnings()` / CP post-install notice.
**Safe steps**: resolve manually via the Shared Resources Library (Import/Create/Skip) — this is the intended workflow, not an error state.

### 10. "Commerce24 marketplace tab shows no packages"
**Likely cause**: `CommerceClient::isConfigured()` is false (no API key/endpoint set), OR the account has no entitled packages.
**Inspect**: plugin Settings → Commerce tab configuration; `CommerceApiException` in logs if a request was attempted and failed.
**Safe steps**: verify settings before assuming this is a bug — an unconfigured connection deliberately shows an empty catalog rather than an error (`23_MARKETPLACE_ARCHITECTURE.md` §10).

### 11. "Starter Kit install hangs or times out"
**Likely cause**: a stage subprocess exceeded `STAGE_TIMEOUT_SECONDS` (900s), or a queue worker isn't running to advance `InstallStarterKitJob`.
**Inspect**: `site7_install_sessions` row status; queue worker logs; whether `php craft site7-studio/install/run-stage <uid>` can be run manually to see raw output (console-only debugging step, not destructive).
**Safe steps**: do not manually mark the session complete in the DB — investigate the actual stage failure first.

### 12. "Test suite reports failure even though my change is correct"
**Likely cause**: pre-existing suite issues, not your change — see `33_TESTING_ARCHITECTURE.md` §10 (3 parse-error files, 3 environment-dependent failures, 2 files with genuine pre-existing assertion drift).
**Inspect**: run your specific test file individually, excluding the 3 known parse-error files.
**Safe steps**: compare against the baseline failure list in `33_TESTING_ARCHITECTURE.md` before concluding your change broke something.

### 13. "Clicking 'Add Section' on a Matrix field silently creates an unwanted block" — RESOLVED
**Likely cause (was)**: a Site7-injected button shared Craft's generic `btn` class and lived inside the same `.buttons` container Craft's own field JS scopes its native add-button selector to (`.buttons .btn:not(.menubtn)`), so Craft bound its own native add handler onto Site7's button too. Most visible when the field has exactly one allowed Entry Type, where Craft's native handler is an instant no-confirmation add. Fixed 2026-08-18 — see `44_CONTENT_BROWSER_MATRIX_INJECTION.md` §11 for the full mechanism and fix.
**Inspect**: if a similar symptom recurs elsewhere, check whether any newly-injected CP control shares a class with, and lives inside a container scoped by, a selector in Craft's own compiled field JS.
**Safe steps**: after editing `src/resources/js/pattern-matrix.js` or `pattern-browser.js`, clear the CP resources cache (`ddev craft clear-caches/all`) — Yii's `AssetManager` serves a content-hashed, non-auto-invalidating published copy, so edits will appear to have no effect until this cache is cleared.
