# 41 — AI Developer Guide

This document is written specifically for an AI coding agent (or a new developer) about to modify this plugin. Read it before touching any code.

## Before Changing Code — an 11-step checklist

1. Identify which feature document (`00`–`43` in this directory) covers the area you're about to touch. If none does, you may be about to build something genuinely new — proceed carefully.
2. Read that feature document's "Important Classes" and "Execution Flow" sections fully.
3. Read the actual source files it references — the documentation is a map, not a replacement for reading the code.
4. Check `36_FILE_OWNERSHIP_REFERENCE.md` if your change writes to any file on disk — confirm you're not bypassing the three-way baseline system.
5. Check `38_SERVICE_REFERENCE.md` to confirm no existing service already does what you're about to build.
6. Check `27_EVENTS_AND_HOOKS.md` if your change should react to or emit an event — reuse `EventDispatcher`/`EventSubscriberInterface`, don't invent a new notification mechanism.
7. Check `05_DATABASE_ARCHITECTURE.md`/`37_DATABASE_TABLE_REFERENCE.md` before adding or changing any table — most new data needs likely already fit an existing table (e.g. `site7_installed_files` is already generic enough for any file type).
8. Check `33_TESTING_ARCHITECTURE.md` for the current known-failing tests before you run the suite, so you don't misattribute a pre-existing failure to your change.
9. Determine whether your change is documentation-only, additive, or modifies existing behavior — the last category requires the most care and the most explicit user/reviewer confirmation.
10. Plan your live verification approach (`34_DEVELOPMENT_WORKFLOW.md`) — a throwaway, fully self-cleaning test against a real environment, before you consider the change complete.
11. Confirm your change does not touch production `templates/`/`frontend/` files outside the ownership rules in `36_FILE_OWNERSHIP_REFERENCE.md`.

## Rules (verbatim, non-negotiable)

- Never create duplicate services if an existing service already performs the job.
- Never create a second template rendering system. `templates/_blocks/{handle}.twig` via `matrix-container.twig` is the only production path, permanently (`13_TEMPLATE_ARCHITECTURE.md`).
- Never write directly to production templates without understanding ownership/baseline behavior (`36_FILE_OWNERSHIP_REFERENCE.md`).
- Never silently overwrite locally modified files. Every file write to a tracked path must go through `PackageUpdatePlanner::classify()` (`19_UPDATE_AND_CONFLICT_HANDLING.md`).
- Never mutate historical version archives. `.s7pkg` files recorded in `site7_package_versions` are immutable once created (`17_PACKAGE_VERSIONING.md`).
- Never create duplicate version rows. Use `VersionManagerService::createVersion() (internally resolveBumpBaseVersion())`/`MarketplaceService::recordVersion()`, which are already dedup-safe.
- Never bypass existing checksum/version mechanisms. `PackageArchiveHelper::computeFileChecksum()`/`computeDirectoryChecksum()` (sha256) is the ONE convention used everywhere.
- Never change database schema without checking migrations (`10_DATABASE_MIGRATIONS` — see `37_DATABASE_TABLE_REFERENCE.md` for the full migration list) — a new column/table needs its own new migration, following the existing `!$this->db->tableExists()` guard pattern.
- Never modify production templates/frontend while working on package internals unless explicitly required by the task.
- Always inspect existing code before implementing a new feature.
- Prefer reusing existing services (`38_SERVICE_REFERENCE.md`) over writing new ones.
- Preserve backward compatibility — e.g. `PackageManifest`'s optional fields (like `ownedFiles`) must remain optional so older packages without them still load correctly (`07_PACKAGE_MANIFEST.md`).
- Test using a temporary/throwaway package/site where appropriate.
- Do not use the shared production development site for destructive tests.
- Clean all test artifacts after testing — including direct SQL deletes where Craft's own service methods prove unreliable for cleanup outside a normal request lifecycle (a documented, deliberate exception, scoped strictly to test cleanup — `34_DEVELOPMENT_WORKFLOW.md`).

## Where should I start if I need to...

| Task | Start here |
|---|---|
| Add a new package type | `06_PACKAGE_ARCHITECTURE.md`, `40_EXTENSION_GUIDE.md` |
| Change how templates are installed/updated | `13_TEMPLATE_ARCHITECTURE.md` §12 |
| Change version numbering | `17_PACKAGE_VERSIONING.md` §12 |
| Change conflict/update logic | `19_UPDATE_AND_CONFLICT_HANDLING.md` §12 |
| Change rollback behavior | `20_ROLLBACK.md` §12 |
| Add a new owned-file type | `21_FRONTEND_FILE_OWNERSHIP.md` §12 |
| Add a Craft resource type to import/install | `04_CRAFT_CMS_INTEGRATION.md` |
| Add a new marketplace backend | `23_MARKETPLACE_ARCHITECTURE.md` §12 |
| Implement package signing | `24_LICENSING_AND_COMMERCE.md` §12 |
| Add dependency resolution for a new resource type | `25_DEPENDENCIES_AND_SHARED_RESOURCES.md` §12 |
| Change backup retention | `26_BACKUP_AND_RECOVERY.md` §12 (note: retention is deliberate, not a bug) |
| Add a new event | `27_EVENTS_AND_HOOKS.md` §12 |
| Add a new CP page/route | `28_CONTROLLERS_AND_ROUTES.md` §12 |
| Add a new console command | `30_CONSOLE_COMMANDS.md` §12 |
| Change Starter Kit install staging | `32_STARTER_KIT_SYSTEM.md` §12 |
| Fix a failing test | `33_TESTING_ARCHITECTURE.md` §10 (check known-failure list first) |

## Known Issues to Not Re-Discover as New Bugs

See `43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md` for the full, consolidated list — includes the 3 test files with a `protected clone $tester;` parse error, the leftover `templates/site7-components/clientLogos.twig` file, package signing's stub state, and Website-import capture gaps.
