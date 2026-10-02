# 36 — File Ownership Reference

A single lookup table for "who is allowed to write this path, and under what condition" — the question most likely to matter when debugging an unexpected file change.

| Path pattern | Owner / Writer | Condition | Doc |
|---|---|---|---|
| `packages/{handle}/manifest.json,fields.yaml,matrix.yaml,template.twig,owned/*` | Site7 Studio (package source side) | Always safe to overwrite wholesale — Site7-owned, never developer-edited in place | `06_PACKAGE_ARCHITECTURE.md` |
| `templates/_blocks/{handle}.twig` | `CraftResourceService` (install), then shared with the developer | First write: content-compare guarded. Subsequent writes: only via `PackageUpdatePlanner::classify()` = `RESULT_SAFE_UPDATE` | `13_TEMPLATE_ARCHITECTURE.md`, `19_UPDATE_AND_CONFLICT_HANDLING.md` |
| Owned-file targets (arbitrary CSS/JS/config paths) | `PackageManagerService::installOwnedFiles()` / `applySafeFileUpdate()` | Identical three-way guard as templates — reuses the same planner | `21_FRONTEND_FILE_OWNERSHIP.md` |
| `templates/_includes/matrix-container.twig` | Host site (RP Craft), pre-existing | **Never touched by Site7 Studio** | `13_TEMPLATE_ARCHITECTURE.md` |
| `templates/site7-components/*` | Dead — no current code path reads/writes here | N/A — confirmed dead, one harmless leftover file (`clientLogos.twig`) | `13_TEMPLATE_ARCHITECTURE.md` |
| `storage/site7-studio/exports/*.s7pkg` | `PackageExportService` | On explicit export/version-creation | `09_PACKAGE_BUILD_AND_EXPORT.md` |
| `storage/site7-studio/marketplace-repo/*.s7pkg` | `PackageBackupService` (auto-backup) + `PackagePublisherService` (explicit publish to Local repo) | Latest-only retention per handle for auto-backup | `26_BACKUP_AND_RECOVERY.md`, `23_MARKETPLACE_ARCHITECTURE.md` |
| `storage/site7-studio/commerce24-cache/*` | `Commerce24MarketplaceRepository::fetchPackage()` | On Commerce24 download | `23_MARKETPLACE_ARCHITECTURE.md` |
| Native Craft resources (Fields, Entry Types, Sections, Volumes, etc.) | `CraftResourceService`, `CraftResourceInstallExecutor` (Starter Kit scope) | Idempotent, lookup-by-handle-first — never blind create | `04_CRAFT_CMS_INTEGRATION.md` |
| Composer/npm state | `ComposerExecutor`/`NpmExecutor` (Starter Kit install stage only) | Only during a Starter Kit subprocess-staged install | `32_STARTER_KIT_SYSTEM.md` |
| Project Config | `ProjectConfigExecutor` (Starter Kit install stage only) | Only during a Starter Kit subprocess-staged install | `32_STARTER_KIT_SYSTEM.md` |

**Universal rule** (restated once more, since this is the single most consequential fact in this documentation set): any path with a recorded baseline in `site7_installed_files` is NEVER overwritten if the live file's checksum differs from that baseline — regardless of which of the above writers is involved. See `19_UPDATE_AND_CONFLICT_HANDLING.md`.
