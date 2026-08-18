# 34 — Development Workflow

## 1. Purpose

Describe the established, repo-proven workflow for making changes to this plugin safely — derived from the actual practice used across Steps 2–8.2 of this plugin's own recent development history.

## 2. What It Does

Not a code feature — a documented process convention for future contributors (human or AI).

## 3. Current Status

**Implemented as team practice** (not enforced by tooling).

## 4. Architecture — the proven workflow

```
1. Audit before code — read the relevant service(s) fully before writing anything
2. Smallest safe change — prefer extending an existing method/service over
   creating a new one
3. Reuse existing infrastructure — never duplicate checksum/baseline/version/
   archive/conflict systems (this is the single most repeated rule in this
   plugin's own history)
4. Live verification — throwaway DDEV scripts that create real temporary
   Fields/Entry Types/files, exercise real service calls, assert, then
   delete every trace (including direct SQL deletes where Craft's own
   service methods proved unreliable outside a normal request lifecycle)
5. Regression check — confirm unrelated production templates/frontend
   remain untouched
6. Explicit approval before commit
```

## 5. Execution Flow

Each of the 8 implementation steps that built the package/template lifecycle system (Sync From Source, Checksum Helper, createVersion() fix, Installed-File Baseline, Update/Conflict Handling, Rollback, Frontend Architecture Audit, Package-Owned File Model + Complete Owned-Files Lifecycle) followed this exact sequence — see plugin git history on branch `cleanup/dead-templates-checkpoint-20260817`.

## 6. Important Classes

Not applicable — this is a process document.

## 7. Data Model

Not applicable.

## 8. Filesystem Impact

Not applicable.

## 9. Events

Not applicable.

## 10. Validation and Safety

**Live DDEV verification pattern** (the specific technique used repeatedly): bootstrap via `require bootstrap.php` + Craft console bootstrap, create real temporary Fields/Entry Types/`_blocks/` files, exercise the real service under test, assert against real results, then delete every trace. Where Craft's own service methods proved unreliable for cleanup outside a normal HTTP request lifecycle, direct SQL deletes were used instead — a documented, deliberate exception to "always use the service layer," scoped strictly to test cleanup.

## 11. Failure Scenarios

Not applicable — process document.

## 12. Developer Change Guide

**If you are an AI agent or new developer about to change this plugin**: follow the sequence in §4 exactly. In particular, step 3 (reuse existing infrastructure) is the rule most likely to be violated by someone unfamiliar with the codebase — see `41_AI_DEVELOPER_GUIDE.md` for the full "Never create a second X" rule list.

## 13. Related Features

`41_AI_DEVELOPER_GUIDE.md`, `33_TESTING_ARCHITECTURE.md`.

## 14. Known Limitations

This workflow is a team convention, not enforced by CI/tooling — nothing currently prevents a change that skips these steps.
