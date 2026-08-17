# 22 — Frontend Tooling and Asset Detection

## 1. Purpose

Document `FrontendToolingScanner` — the read-only detector that powers frontend candidate discovery for owned files and Website import's environment snapshot.

## 2. What It Does

Scans the host site's frontend build system and npm configuration without modifying anything, producing candidate file lists and dependency summaries consumed by other services.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
FrontendToolingScanner
   ├── detect() — identifies the build system in use (from config file presence)
   ├── captureNpmDependencies() — reads package.json dependencies/devDependencies
   └── listCandidateFrontendFiles() — enumerates candidate CSS/JS/config/asset files
         for the owned-files selection UI (21_FRONTEND_FILE_OWNERSHIP.md)
```

## 5. Execution Flow

1. `detect()` — checks for known frontend config files at the project root (returns empty/null summary if none found — never an error).
2. `captureNpmDependencies()` — reads `package.json` if present, returns `dependencies`/`devDependencies`; returns empty arrays if no `package.json` exists.
3. `listCandidateFrontendFiles()` — scans configured frontend directories, returns `{path, type}` candidates for the CP's owned-file selection step (`ResourceImportController::actionListFrontendFileCandidates()`).

## 6. Important Classes

**`FrontendToolingScanner`**
`src/services/FrontendToolingScanner.php`
Important methods: `detect()`, `captureNpmDependencies()`, `listCandidateFrontendFiles()` (added Step 8.1).
Called by: `WebsiteImportService` (environment snapshot), `ResourceImportController::actionListFrontendFileCandidates()` (owned-file selection).

## 7. Data Model

None — pure filesystem read, no persistence of its own. Results are consumed and stored by callers (`manifest.json`'s `frontendTooling`/`npmPackages` fields for Website packages; `ownedFiles` for owned-file capture).

## 8. Filesystem Impact

**Read-only.** Never writes, modifies, or deletes any file.

## 9. Events

None.

## 10. Validation and Safety

Every method degrades gracefully to an empty result when the expected config isn't found — never throws for "no frontend tooling detected," since that's a valid and common state (verified by `FrontendToolingScannerTest`).

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| No `package.json` at project root | `captureNpmDependencies()` returns empty arrays |
| No recognized build config files | `detect()` returns an empty/null summary |
| Configured frontend directory doesn't exist | `listCandidateFrontendFiles()` returns no candidates for that path, not an error |

## 12. Developer Change Guide

If adding detection for a new build tool: extend `detect()`'s config-file check list — keep the scanner read-only; any file-copying belongs in the caller (`captureOwnedFiles()`/`copyFrontendConfigFiles()`), not here.

## 13. Related Features

`21_FRONTEND_FILE_OWNERSHIP.md`, `15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md`.

## 14. Known Limitations

None confirmed — scope is deliberately limited to detection/enumeration, not build execution.
