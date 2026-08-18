# SITE7 Studio — Development Rules

SITE7 Studio is a Craft CMS 5 plugin. `docs/` (44 files, `00_OVERVIEW.md` → `99_COMPLETE_ARCHITECTURE_MAP.md`) is the audited source of truth for its architecture (validated against source on 2026-08-17 — treat as authoritative but still re-verify against current code, since code can drift after an audit).

## Golden rule

**Documentation → Source Code → Tests → Implementation.** Never Request → Code. Before editing anything, know which doc covers the area, what the current code actually does, and whether something already solves the problem.

## Before any change — mandatory order

1. Identify the affected subsystem (see doc map below) and read the relevant doc(s) in `docs/`.
2. Read the actual implementation — grep/read the real service/controller/model, don't trust the doc's paraphrase alone.
3. **Search for existing functionality before writing anything new** — see "Do not duplicate" below.
4. Trace callers, migrations, events/controllers/routes, and tests touching your area.
5. Check `43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md` — don't accidentally "fix" a known issue as a side effect of an unrelated task; if your change touches one, call it out explicitly instead.
6. Write a short plan (see Output Format) before touching code. **For anything beyond a small, obviously-scoped fix — a new service, a schema change, a change to any of the invariants below, anything touching a known issue — present the plan and stop; wait for explicit approval before editing code.**
7. Only then implement — the smallest safe change that satisfies the request. **Never delete or rewrite existing code on the assumption it's obsolete or dead — prove it first** (no callers via grep, confirmed by the relevant doc, or explicitly confirmed by the user), and say what you checked.
8. Run the smallest relevant test, then relevant regression tests. For install/sync/update/rollback/Starter Kit work, also run a live DDEV verification (`33` and this file's Testing section) — unit tests alone have not caught real bugs in this codebase's history.
9. If the change altered architecture (new service, changed lifecycle behavior, new invariant, fixed a documented known issue) — update the relevant `docs/*.md` file(s) to match. Keep the edit as small as the fix that motivated it; don't rewrite a doc wholesale for one changed fact.
10. `git status` / `git diff` / `git diff --stat` — confirm nothing unrelated changed.
11. Report using the Output Format below, including what remains (follow-up work, anything explicitly out of scope).

## Documentation map

Read what's relevant to the task, not the whole set.

| Area | Docs |
|---|---|
| Orientation | `00_OVERVIEW`, `01_ARCHITECTURE`, `02_DIRECTORY_STRUCTURE`, `99_COMPLETE_ARCHITECTURE_MAP` |
| Bootstrap / Craft integration | `03`, `04` |
| Database | `05` (narrative), `37` (flat table lookup) |
| Package lifecycle (index: `06`) | `06` Architecture, `07` Manifest, `08` Authoring, `09` Build/Export, `10` Import, `11` Install, `12` Uninstall |
| Templates (read before touching rendering) | `13` |
| Import Existing Section/Page/Website | `14`, `15` |
| Versioning / Sync / Baseline / Update / Rollback — **one system, read all five together** | `16`, `17`, `18`, `19`, `20` |
| Frontend owned files | `21`, `22` |
| Marketplace / Licensing / Dependencies / Backup | `23`, `24`, `25`, `26` |
| Events / Controllers / CP / Console / Security | `27`, `28`, `29`, `30`, `31` |
| Starter Kit (separate parallel system — don't conflate with `19`) | `32` |
| Testing | `33` |
| Reference | `34`–`42` |
| Known issues (read before ANY architecture change) | `43` |

## Non-negotiable architecture invariants

1. **Template rendering** is `templates/_includes/matrix-container.twig` → `templates/_blocks/{handle}.twig` only. `templates/site7-components/` is dead (one harmless leftover file) — never make it a renderer again. (`13`)
2. **Package source** (`packages/{handle}/`) and **installed resources** (`templates/_blocks/`, live Craft Fields/Entry Types) are different concerns with different safety rules — never conflate overwriting one with overwriting the other. (`06`)
3. **Versioning is single-path**: `VersionManagerService::createVersion()` → `PackageExportService`/`PackageArchiveHelper` → `MarketplaceService::recordVersion()`. Never hand-roll a second version-recording mechanism. Archives are immutable — never rewrite or delete a historical `.s7pkg`. (`17`)
   - Known exception, **not** a pattern to copy: `PageUpdateService` currently bypasses this path entirely (`18` §5a) — a documented bug, not precedent.
4. **Sync From Source** must stay no-op-safe and one-version-per-sync — true for Section packages today. Page packages do **not** currently have this guarantee; don't assume parity between the two package types without checking `18` §5a first.
5. **Three-way update/conflict**: use `PackageUpdatePlanner::classify()` + `InstalledFileBaselineService` for any "is this file safe to overwrite" logic — never a new parallel mechanism. Never silently overwrite a locally modified file. Note: `classify()` returns `RESULT_CONFLICT` even when LIVE==INCOMING; that short-circuit exists only in Rollback's post-processing step, not in `classify()` itself. (`19`)
6. **Rollback** restores package source and re-runs the same `PackageUpdatePlanner`; it never creates a new version row and never modifies a historical archive. (`20`)
7. **Frontend owned files** are explicit via `PackageManifest::$ownedFiles` — never inferred from filename/handle. (`21`)
8. Prefer **Craft's native service APIs** (Fields, Entry Types, Sections, Volumes, Category Groups — see `CraftResourceService`/`CraftResourceInstallExecutor`) over manually editing Project Config YAML. (`04`)
9. `PackageActionController`'s `install`/`enable`/`disable`/`remove` actions currently have **no permission gate** (documented gap — `28` §10, `31`, `43` #16). Don't assume a destructive controller action is authorized just because it already works.
10. **Starter Kit** (`32`) is a structurally separate three-way system from #5 — don't merge them. Its Build phase (`StarterKitBuilder`) is CLI-only today, unlike Install (`32` §14).

## Do-not-duplicate rule

Before writing a new service, helper, validator, planner, model, migration, controller, or command: grep `src/` for an existing equivalent first. `38_SERVICE_REFERENCE.md` lists only ~65 of ~110 actual service classes (see its own coverage note) — absence from that table is not proof nothing exists. Also `Glob src/services/**/*.php` directly.

## Testing & git safety

- `git status` before touching anything. Report and **preserve** any pre-existing unrelated changes — never revert, stash, or clean up files you didn't create for this task.
- Prefer the smallest focused test, then relevant regression tests (`33`). The Codeception `unit` suite currently fails as a whole-suite run due to a 3-file parse error (`33` §10, `43` #1) — run individual files, or fix that specific typo first if you need the full suite green.
- For lifecycle changes (install/sync/update/rollback/Starter Kit), a live DDEV verification is required, not optional — run it against an isolated target where possible rather than mutating the shared dev site. Clean up any test packages, DB rows, generated archives, or template files afterward.
- Before reporting done: `git status` / `git diff` / `git diff --stat`, confirm no unrelated files changed.
- If an architecture change was made, confirm the matching `docs/*.md` update landed alongside it — a code change to a documented invariant without a doc update is incomplete.

## Output format for development tasks

```
### Understanding
### Existing Implementation
### Relevant Documentation
### Impacted Code
### Reusable Existing Logic
### Risks
### Implementation Plan
### Changes
### Documentation Updated (or "none needed — no architecture change")
### Verification
### Git Safety
### Remaining Work
```
