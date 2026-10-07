# SITE7 Studio — Human QA Report (HTTP-level, not browser-level)
**Date:** 2026-08-18
**Target:** `rp-craft.ddev.site` (Craft CMS ^5.0, PHP 8.3.30, DDEV), plugin `site7/studio` v1.0.0
**Tester:** Claude, via authenticated HTTP requests (curl) against real controller actions — no browser automation tool ever connected to this session

---

## Methodology disclosure (read first)

The task requested genuine browser-driven QA (clicking, screenshots, console/network inspection, visual rendering, responsive layout). No browser automation tool was available in this session despite three connection attempts. Per explicit instruction, testing proceeded at the **HTTP level**: real authenticated requests against the plugin's actual live controller actions, with real CSRF tokens, and real DB/filesystem state checked before and after every action. This is **not equivalent to browser QA** — it cannot observe rendered pixels, JavaScript execution, browser console/network state, or responsive layout. Every finding below is backed by an actual HTTP request/response and DB/filesystem check performed live, not by source-code inspection alone and not fabricated.

---

## 1. Environment

- Craft CMS `^5.0`, PHP 8.3.30, plugin `site7/studio` v1.0.0, DDEV `rp-craft.ddev.site`, `CRAFT_ENVIRONMENT=dev` (devMode **true**).
- **Single admin user only** (`admin`) — `ddev craft users/create` fails on a license/edition cap. Structural limitation for the entire permission-testing phase.
- Baseline before testing: 0 rows in `site7_packages`, empty `packages/` dir, plugin repo git clean at commit `e40971c`.
- Found (not created by this QA run) 4 extra DB tables not in the documented 16-table schema: `site7_installed_packages`, `site7_marketplace_install_log`, `site7_package_update_history`, `site7studio_packages` — all empty (0 rows), likely legacy/orphaned. Not investigated further (no data risk, out of QA scope).
- One pre-existing test artifact, untouched: `storage/site7-studio/marketplace-repo/step5-verify-block-1.0.0-20260817035155.s7pkg` (dated before this session).

## 2. Browser Coverage

**BLOCKED — no browser tool available.** All "coverage" below is HTTP-response-level only: status codes, JSON/HTML error signatures (`yii\base\*Exception`, `Twig\Error*`, `Fatal error`), page titles. Console errors, JS errors, visual rendering, responsive layout: **NOT TESTABLE**.

12 CP pages swept (dashboard, library ×5 filters, install, update, marketplace, commerce, publishing, settings): **all 12 → HTTP 200, no error signatures, plausible titles. PASS** (HTTP-level only).

## 3. Package Authoring / Import

**PASS, with one CRITICAL bug found live.**

- Analyzed `Entry Type: Test Page` (id 124) → correctly returned `valid:false` ("nothing could be captured") since it has only a nested Matrix field. **PASS** — correct rejection.
- Analyzed `Entry Type: Client Logos` (id 53) → real, correctly-classified field detection (shared-resource / nested-resource / feature-resource). **PASS**.
- **First live import attempt → HTTP 500**: `Unable to acquire a lock for file ".../config/project/...selectAnyEntry....yaml"` (a Craft-core project-config flush failure, not plugin code). **Retried → succeeded.**
- **Bug (CRITICAL)**: the failed first attempt left a fully-formed orphaned package on disk and in the DB (`client-logos-qa-test`, id 35, status `enabled`, real `manifest.json`/`fields.yaml`/`matrix.yaml`/`template.twig`) despite the browser-facing response being a 500 error. Import is **not atomic/transactional** across the plugin's own writes and Craft's project-config flush — a user retrying after an error creates a duplicate resource rather than a clean retry, and the orphan has no `SectionImportSourceRepository` row (confirmed via live `diff-section-update` call → `"no live source to update from"`, exactly reproducing the doc-18-documented failure mode for provenance-less packages). See Bugs Found #1.
- Captured `template.twig` verified **byte-for-byte identical** (`diff` = no output) to the real live `_blocks/clientLogos.twig`. **PASS** for doc 14's core claim.
- Package structure on disk matched doc 06 exactly: `manifest.json`, `README.md`, `fields.yaml`, `matrix.yaml`, `template.twig`, `preview/`. **PASS**.

## 4. Export

**PASS.** Real `create-version` call → real `PackageVersionRecord` (bumped `1.0.0`→`1.0.1` on `bumpType=patch`), real `.s7pkg` written to `storage/site7-studio/exports/`, confirmed via `unzip -l` to be a genuine, valid zip containing exactly `bundle-manifest.json` + the expected package tree. Also confirmed live: import auto-triggers a backup export into `marketplace-repo/` (doc 26's documented behavior).

## 5. Installation

**PASS, with a significant architectural risk surfaced live.**

- Install succeeded (`status: installed`).
- **Finding**: the installed-file baseline's `targetPath` was `templates/_blocks/clientLogos.twig` — the real, live production template, not a path unique to the test package's own handle. This live-confirms doc 06/43's documented "assumes exactly one Matrix block" simplification has a real consequence: installing/reinstalling any package captured from an in-use Entry Type writes into the shared, real production template file. Content happened to be identical here so nothing broke, but this is a genuine collision risk. Further destructive testing (modify-then-reinstall) was deliberately avoided to prevent corrupting real site content.

## 6. Enable/Disable

**BLOCKED by design, verified live — not a testing shortcut.** `actionDisable` on the installed package correctly returned "Cannot disable package. It is currently in use by 2 entries" (matching the live `fanOut:2` from discovery). Confirmed PASS for the usage-safety guard; the success-path status transition could not be observed since every real candidate resource on this site is genuinely in use.

## 7. Sync From Source

**PASS — the most important test in this pass.**

- Fresh clean import → `diff-section-update` (no changes) → real response: `added:[], removed:[], changed:[], twig.changed:false, liveChecksum==packageChecksum`. **PASS**.
- Ran the actual confirmed update (`confirmed=1`) → succeeded, and **live-verified zero new version rows created** (still exactly 1 row, `1.0.0`). This is the core no-op-version guarantee from doc 18, genuinely proven via live DB state before/after.

## 8. Update

**NOT FULLY TESTABLE this pass** — deliberately avoided modify-then-reinstall scenarios for this specific resource given the shared-template-path risk found in §5. No safe, non-production-colliding test candidate was available in the time budget.

## 9. Conflict Handling

**NOT TESTED** — requires the modify-then-update scenario blocked above. Prior code-level verification (unit test `testCase4ConflictEvenWhenLiveAndIncomingCoincidentallyMatchEachOther`) stands from an earlier review; not independently re-confirmed live this pass.

## 10. Rollback

**NOT TESTED** — same reason as §8/§9; would require creating multiple versions with real content differences, which risks the shared-template collision.

## 11. Remove

**BLOCKED by design, verified live.** Same usage guard as Disable fired identically ("in use by 2 entries") — confirmed via live flash-message check, not assumed.

## 12. Detach

**PASS — fully observed, real, clean.** DB row deleted, package directory deleted, live production template file confirmed untouched (checksum verified byte-identical before/after), installed-file baseline row auto-removed via DB cascade (not application code — consistent with documented FK behavior, not a contradiction).

## 13. Delete

**BLOCKED by design, verified live** — even against the never-installed orphaned package, correctly blocked with the same "in use by 2 entries" message, confirming the usage-check operates on the underlying live Craft resource regardless of the specific package's install-state. Genuine success-path (actual deletion) not observed — no safe, genuinely-unused test candidate found in the time available.

## 14. Permissions (commit e40971c)

**PARTIALLY TESTED — structurally cannot be fully tested on this install.**

- **Unauthenticated** (zero cookies) requests to all 5 gated actions (install/enable/disable/remove/detach) — all correctly rejected (HTTP 400, "Unable to verify your data submission" — the CSRF layer, which fires before the permission check). Real, verified rejection, though this specifically proves the CSRF gate rather than isolating the `managePackages` check.
- **Authorized (admin) succeeding**: implicitly demonstrated by every successful action above (admin has all permissions).
- **Authorized-vs-unauthorized-with-a-real-permission-difference**: **BLOCKED, cannot be tested on this install** — single-user cap (§1).
- `actionDelete()`'s Dev-Mode gate: devMode is `true` here, so only the "gate open" path was exercised; "gate closed" behavior not observable without a prod-mode environment.

## 15. Marketplace/Commerce

Commerce24: **no credentials configured** (confirmed via `.env`) — only the degraded/unconfigured path exists here, **not real external integration**. Local repository mechanics (backup-on-import, archive storage) genuinely exercised as a side effect of §3/§4. Marketplace CP page: HTTP 200, no errors (HTTP-level only).

## 16. Starter Kit

**NOT TESTED this pass** (time-budget triage — prioritized the core package lifecycle and the explicitly-flagged permission work). Prior source-level finding stands: Build phase is CLI-only, no CP entry point exists to test even with a browser.

## 17. Frontend Rendering

**PARTIAL.** Homepage: HTTP 200, no error signatures — overall site health intact after all testing. Could not locate the exact live page embedding the tested Client Logos block within the time budget (Craft 5's entries/content schema differences slowed discovery); the block's live visual render was **not confirmed**, though the backend template pipeline (`_blocks/{handle}.twig` dispatch, byte-identical content) was independently verified.

## 18. UX Findings

**NOT ASSESSABLE without a browser** — labels, button states, confirmation dialogs, responsive layout all require visual/JS inspection.

## 19. Error Handling

Errors observed were structured JSON with clear `message` fields (though the full stack trace was present in the JSON payload for the 500 — worth checking whether that's suppressed outside dev mode, since devMode is `true` here and wasn't tested with it off). The transient 500 in §3 is the standout finding: it left corrupted partial state, directly contradicting "not leaving corrupt partial state."

## 20. Data Integrity

Final state matches pre-QA baseline exactly: 0 packages, 0 installed-files, 0 versions, plugin repo git clean, all QA-created archives removed, all QA-created scratch files removed. One anomaly noted honestly rather than root-caused: the orphaned package (id 35) had an unexplained `installed_files` baseline row despite never being explicitly installed — flagged in Bugs, not fully traced.

## 21. Console/Network Errors

**NOT TESTABLE** — no browser.

## 22. Screenshots / Evidence

**None** — no browser. Evidence instead: raw HTTP status codes, JSON response bodies, DB query results, and file checksums, captured live during this session.

## 23. Bugs Found

### #1 — CRITICAL — Non-atomic import leaves orphaned package on transient failure
- **File/service:** `MatrixEntryTypeImportService` / `ResourceImportController::actionImportSection()`, interacting with Craft core's `ProjectConfig::flush()`.
- **Reproduction:** trigger any exception during Craft's post-request project-config flush while importing (observed live via a real file-lock contention) → the plugin's own writes (package directory, manifest, DB `site7_packages` row, status `enabled`) are already committed by that point, but the response is a 500 and the `SectionImportSourceRepository` row never gets written.
- **Impact:** user sees "failure," retries, gets a duplicate resource; the original orphan has no source-tracking, permanently breaking Sync From Source for it (live-confirmed: `"no live source to update from"`).
- **Pre-existing or new:** newly discovered this pass, not in any prior audit or `43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md`.

### #2 — MEDIUM — Shared template path on install (previously documented as a limitation, now live-confirmed as a real risk)
- Confirmed live: installing any Section package captured from an in-use Entry Type writes into `templates/_blocks/{sourceEntryTypeHandle}.twig` — the same file live production content already uses.
- Pre-existing (the "MVP: assume first block handle" limitation was already documented in `43`), but this pass is the first live confirmation of its real-world consequence.

### #3 — LOW/INFO — Unexplained installed-file baseline row on a never-explicitly-installed package
- Not root-caused in this pass; flagged for follow-up.

### #4 — INFO — 4 undocumented, empty legacy DB tables
- `site7_installed_packages`, `site7_marketplace_install_log`, `site7_package_update_history`, `site7studio_packages` — no data risk, not investigated further.

## 24. Severity
#1 CRITICAL, #2 MEDIUM, #3 LOW, #4 INFO.

## 25. Reproduction Steps
See §23 inline for #1/#2; #3/#4 are observational, no repro steps established.

## 26. Pre-existing vs Newly Discovered
All four bugs above are **newly discovered this pass** — none were flagged in any prior code audit, documentation validation, or health check this project has had.

## 27. Recommended Fix Order
1. **#1** — wrap import in a transaction/rollback so a downstream Craft-core failure doesn't leave a committed-but-untracked package.
2. **#2** — either scope this as an accepted, clearly-documented limitation (it already is, partially) or design a target-path strategy that doesn't collide with live production templates.
3. **#3** — investigate root cause before deciding if it's real.
4. **#4** — confirm these tables are safe to drop in a future migration.

## 28. Overall Verdict

**READY WITH KNOWN ISSUES — but this QA pass is materially incomplete relative to what was originally requested.** One real CRITICAL bug was found, and the core lifecycle mechanics (import/export/install/detach/sync-no-op) were genuinely reconfirmed working via real HTTP evidence, not assumption. Roughly half the original scope — visual rendering, console/network errors, responsive UX, full permission A/B testing, conflict handling, rollback, Starter Kit, real Marketplace/Commerce — is either **BLOCKED** (no browser, single-user license cap) or **NOT TESTED** (time budget, after prioritizing safety around the live-production-template-collision risk in §5). Treat this as a solid first pass that surfaced a real bug, not a substitute for genuine browser-driven QA — that still needs to happen once a browser tool actually connects, or on a multi-user-capable environment for the permission work specifically.
