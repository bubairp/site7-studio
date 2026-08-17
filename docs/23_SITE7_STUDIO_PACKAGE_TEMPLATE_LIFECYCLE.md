# SITE7 Studio Package & Template Lifecycle

Status: **Architecture approved, implementation in progress (Steps 3–7 of the version-integrity work, see "Implementation status" below).** Describes the stable, permanent lifecycle for a Section/Template package from import through uninstall. This document is the architecture — implementation-phase detail (which class does what today vs. what's still being built) lives in the individual Step commits' messages and, once superseded, is not repeated here.

## Lifecycle

```
RP Craft DEV (templates/_blocks/, live Craft Entry Type)
    │
    ▼
Import Existing Section
    │
    ▼
Package Source (packages/{handle}/)
    │
    ▼
Version (immutable, archived)
    │
    ▼
Sync From Source ──(no meaningful change)──► stays on current version
    │
    (meaningful change)
    ▼
New Version (immutable, archived)
    │
    ▼
Build / Publish (.s7pkg)
    │
    ▼
Install (site's templates/_blocks/, baseline recorded)
    │
    ▼
Update (baseline vs. live vs. incoming, three-way comparison)
    │
    ▼
Rollback (restores a prior immutable version, history intact)
    │
    ▼
Uninstall (removes package-owned files only when safe)
```

## Core rules

**`templates/_blocks/` is the real production runtime.** All rendering goes through `templates/_includes/matrix-container.twig`'s `{% include "_blocks/" ~ itemField.type.handle %}` dispatch. This is the one and only place installed package templates end up live.

**`templates/site7-components/` is not a production renderer and must not become one.** It was an earlier install target that was never wired into `matrix-container.twig` — confirmed dead, and package installation (`CraftResourceService::generateResources()`) no longer writes to it; it targets `@templates/_blocks/{blockHandle}.twig` directly. No new code should reintroduce a second rendering tree under `templates/`.

**Package source preserves the real relative install path.** A package's `template.twig` corresponds to a specific `templates/_blocks/{handle}.twig` on the target site — the package remembers *which* file it owns, not just "a" Twig file, so install/update/uninstall can always identify the correct target unambiguously.

**Import Existing Section copies the real existing implementation, never a generic stub.** When a live `templates/_blocks/{entryTypeHandle}.twig` exists, it's copied byte-for-byte into the package (`MatrixEntryTypeImportService::writeTemplateTwig()`); a generic field-name stub is only ever generated as a fallback when no real file exists to copy.

**Every package version is a complete, immutable snapshot.** Once created, a version's recorded files never change. Creating a new version never mutates a previous one, and previous versions are never deleted as a side effect of any normal operation (only explicit package deletion removes version history, and that is a separate, deliberate action outside this lifecycle).

**Every version has a reproducible archive and checksum.** A version row is not considered complete unless it has a real `.s7pkg` archive path and a deterministic content checksum (`PackageArchiveHelper`'s sha256-per-file convention) — the archive must be sufficient on its own to reproduce the package's complete state at that version, reusing the same export/checksum machinery already used for publishing and distribution rather than a second archival mechanism.

**Sync From Source creates a new version only when something meaningfully changed.** Sync re-reads the live Entry Type's field layout and the live `templates/_blocks/{handle}.twig` content, compares both against the package's currently-recorded state, and takes no action — no new version, no new archive, no new database row — when nothing differs. A version bump only happens when a real difference is detected and confirmed.

**Frontend resources participate in the package lifecycle on the same terms as templates.** Where a package captures frontend files (config, CSS/JS it owns), those files are part of what gets checksummed, versioned, and archived — they are not a separate, untracked concern bolted on afterward. (Scope note: this applies to files a package actually owns/captures; it does not invent a "live source" for frontend files where none is tracked — see the Sync From Source implementation notes for the current field/Twig-only scope of live-source comparison.)

**Installed files get an install-time baseline.** When a package's file is installed onto a site (e.g. `template.twig` copied into `templates/_blocks/{handle}.twig`), the checksum of what was actually written is recorded as that file's baseline — distinct from both "the package's current state" and "the file's current live state," so later operations can tell the difference between "the package changed" and "someone edited the installed file directly."

**Local modifications are never silently overwritten.** Any operation that would replace an installed file (update, rollback) must first compare the file's live state against its recorded baseline. If the live file no longer matches its baseline, that file has been locally modified and is treated as a conflict requiring explicit review — never auto-overwritten, never silently skipped without surfacing that fact.

**Updates use a three-way comparison: baseline, live, and incoming.** Comparing only "installed file vs. new package version" cannot distinguish "the file was never touched, safe to update" from "the file was locally edited, update would destroy that edit" — both look identical to a two-way diff. The baseline (what was actually installed) is what makes the distinction possible:

| Live vs. baseline | Incoming vs. baseline | Outcome |
|---|---|---|
| unchanged | unchanged | nothing to do |
| unchanged | changed | safe update |
| changed | unchanged | local-only edit — leave alone, no conflict |
| changed | changed | conflict — manual review |
| file missing | — | conflict — "deleted locally" |

**Rollback restores an existing immutable version without deleting version history.** Rolling back to an earlier version reuses that version's own archive (never a separate rollback-specific storage mechanism) and reuses the existing install/update pipeline to apply it — including the same baseline/live/incoming conflict check any other update goes through, since a rollback that would silently clobber a local edit is exactly the failure mode the conflict system exists to prevent. Every version created before and after the rolled-back-to version remains in version history, untouched.

**Uninstall removes package-owned resources only when safe.** A file or Craft resource is only removed if it can be confirmed to still match what the package installed (or its subsequent safe updates) — anything locally modified is left in place and reported, not deleted, on the same principle as update: never destroy a local change silently.

## Known contradiction in existing docs

`docs/21_TEMPLATE_ARCHITECTURE.md` (root-level, line 19-21 and line 56) and `docs/22_TEMPLATES_GUIDE.md` still describe `templates/site7-components/` as the plugin's live install target ("UNCHANGED — plugin-managed, auto-generated, do not touch") and note only that nothing yet includes from it. This is now stale: `CraftResourceService::generateResources()` installs into `templates/_blocks/{blockHandle}.twig` directly, and `site7-components/` is confirmed dead — not wired into rendering, not written to by current install code. Those two docs need a correction pass; not made here since that's a separate, narrowly-scoped edit outside this document's purpose (this doc records the current, correct architecture; it doesn't rewrite the other two).

## Implementation status

The rules above describe the target architecture, approved and in active implementation as of 2026-08-17. Confirmed already true in current code: real-`_blocks/`-file import, `_blocks/`-targeted install with a live byte-comparison guard, correct semver bump logic, and an `archivePath`/`checksum` column on `site7_package_versions`. In progress: routing all version-creation paths (manual bump and Sync From Source) through the existing export/checksum/archive infrastructure so every version satisfies the "reproducible archive and checksum" rule without exception; the install-time baseline table; the three-way update/conflict planner; and rollback. See git history on `plugins/site7-studio` for the current state of each.
