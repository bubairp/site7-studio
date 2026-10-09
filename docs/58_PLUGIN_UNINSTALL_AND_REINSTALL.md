# 58. Plugin Uninstall and Reinstall

## 1. Purpose

Uninstalling Site7 Studio (Settings → Plugins) runs `Install::safeDown()`, which drops **every plugin table**: packages and their statuses, versions, installed-file baselines, import links, shared resources, sync history, sessions. The **site itself stays**: sections, fields, entry types, the page builder, entries, assets, `_blocks` templates and frontend files are ordinary Craft/project data, and the plugin has no front-end runtime hooks. Craft also removes `plugins.site7-studio` from project config, so the plugin's settings go too.

Without its tracking, a reinstalled plugin thinks nothing is installed. Library updates (`53`) skip every package (they need status `installed`/`enabled`), and installing a package again collides with its own block type, fields and template. This feature keeps the tracking across the uninstall.

## 2. How it works

| When | What | Where |
|---|---|---|
| Uninstall, before the tables drop | `TrackingSnapshot::writeBeforeUninstall()` writes `storage/site7-studio/uninstall-snapshot.json` | `Site7Studio::beforeUninstall()` |
| Install, after Craft committed and wrote project config | Snapshot there: `TrackingSnapshot::restore()`. No snapshot: `LibraryReconciler::reconcile()` | `Site7Studio::afterInstall()` hooks `Plugins::EVENT_AFTER_INSTALL_PLUGIN` → `TrackingSnapshot::restoreAfterInstall()` |
| Any time | `php craft site7-studio/library/reconcile [--dry-run]`, `.../snapshot`, `.../restore [path] [--dry-run]` | `LibraryController` |

Both CP and `php craft plugin/uninstall` / `plugin/install` go through `Plugins::uninstallPlugin()` / `installPlugin()`, so both run the hooks. (`plugin/uninstall --force` on a **disabled** plugin skips `Plugin::uninstall()`, so no snapshot.)

Why the after-install event, not `afterInstall()` itself: `afterInstall()` runs inside Craft's install transaction, and `installPlugin()` afterwards **replaces** the whole `plugins.site7-studio` project-config node - restored settings would be wiped.

## 3. The snapshot

`schemaVersion` (1), plugin handle/version/schemaVersion, `createdAt`, `craftVersion`, `libraryPath`, `settings`, `matrixField` (`uid`, `id`, `handle`), `excluded` (what was left out, for a reader), `tables` (raw rows, `TrackingSnapshot::TABLES`, parents first).

**Never in it**
- `site7_install_sessions`, `site7_sync_sessions`: transient.
- `site7_packages.entitlementRemovableOn`, `verifiedPricingType`, `signatureStatus`, `signatureKeyId`: licence data. Entitlements come back only from Commerce24 (`24`, `52`); a local file must not be able to set a package's verified pricing.
- Settings `commerce*` and `defaultPackage`: Commerce24 stays as configured (they're usually in `config/site7-studio.php`/`.env`, which survive anyway). Settings are read from project config, so config-file overrides are never written either.

**Files.** One pending snapshot: a new one renames an unrestored older one `uninstall-snapshot.superseded-<time>.json`. A restored one becomes `uninstall-snapshot.restored-<time>.json`. Nothing is deleted automatically. The file is written via `.part` + rename. A failed write logs a warning (`site7-studio` log) and the uninstall goes on.

## 4. Restore

`TrackingSnapshot::restore($dryRun, $path)`:

1. **Settings** (project config, before the DB transaction): only settings empty on this site are filled. The page builder is resolved by UID, then by handle (flagged), else left empty (flagged). A restored `libraryPath` re-points `@packages` before step 2.
2. **One transaction**: `discoverPackages()` creates a row per Library folder (`available`), then for each table in order:
   - `site7_packages`: matched **by handle** to the discovered row. Not in the Library, or failing validation → skipped with its child rows (they stay in the file). Manifest columns (name, version, type…) stay as discovery read them: the Library wins. Restored: status, `authoringStatus`, `creatorId` (nulled if the user is gone), `uid`, `dateCreated`. **Only rows still `available` are touched** - anything else is "kept".
   - Section status check (`checkedSectionStatus()`): none of its block types on the site → `available`; `enabled` but not on the page builder → `installed`. Flagged.
   - Child rows: `id` dropped, `packageId`/`sharedResourceId` remapped through old→new id maps, columns intersected with the current schema (tolerates added/dropped columns), skipped if a row with the same unique key already exists (`TABLES[...]['unique']`).
   - Craft references: section import sources by entry type (or section) UID, else handle (UID rewritten); page import sources by entry UID; website import sources need at least one of their entries; shared resources re-resolve `craftId`/`craftUid` from the field UID, else handle. Missing → skipped (or, shared resources, restored without the field) and flagged. Installed files whose file is gone are restored and flagged (a deleted file stays deleted on update, `19`).
3. Commit, rename the file. Dry run: the same transaction rolled back, settings not saved. Failure: rollback, warning logged, file left for a retry.

Restore is **fill-in only**, so running it on a site with tracking (`restore --dry-run` on the author site) reports everything kept/skipped and never rolls data back.

## 5. Reconcile (no snapshot)

`LibraryReconciler::reconcile($dryRun)` only adds tracking, never touches Craft resources:
- For every entry type on the site, `PackageManagerService::sectionPackageForEntryType()` (the matching Import uses). An `available` Section package found → `enabled` if a block is on the page builder, else `installed`.
- Baselines (`InstalledFileBaselineService::record()`) where no package owns the path: the first block's `templates/_blocks/<handle>.twig` with the **Library** `template.twig` checksum - so a template that differs counts as edited here and updates keep it (`19`); owned files (`21`) only when identical to the Library copy.
- Page builder setting, only when empty and exactly one Matrix field holds the most Library blocks.

- **Opt-in, `--site-content`** (`reconcile($dryRun, true)`): Themes, Templates and Library Starter Kits too. A Template whose page (`template.json` `entryUid`) is on the site, a Theme whose page builder field (`schema.json` `pageBuilderField` UID) is, and a Kit whose Theme and Templates all matched become `enabled`, as their installers leave them. Never automatic and never on the author site: the packages were built from its pages, so the same UIDs exist there, and marking them installed would expose the author's pages to Library updates.

It runs automatically (sections only) after an install without a snapshot (cheap: one discovery pass; on a fresh site it finds nothing). On a customer site, run `reconcile --site-content` afterwards: without it the Theme/Kit stay `available`, so Library updates skip them and page packs refuse to install (`KitInstaller::siteHasKit()` is false).

## 6. Uninstall notice

Settings → System tab: "Uninstalling Site7 Studio" pane (what stays, where the snapshot goes, licences from Commerce24, the reconcile command, a pending/last-restored snapshot). Settings → Plugins: a one-line note under the plugin's row (`#plugin-site7-studio .plugin-details`), injected from `Site7Studio::attachEventHandlers()` on `EVENT_BEFORE_RENDER_PAGE_TEMPLATE` for `settings/plugins`. Craft's own "you will lose all of its associated data" confirmation can't be changed.

## 7. Verified (2026-10-09)

**Real cycle on a throwaway customer site** (`~/my-project/site7-uninstall-test`, Craft 5.10.8.1, connected to the local Commerce24 with its own demo-customer key; Default Starter Kit downloaded and installed from Commerce24: Theme, 6 blocks, 3 Templates; 2 extra pages with matrix content). State compared each step: 16 sections, 104 entry types, 169 fields, 119 entries (91 matrix), 20 assets, handles + UIDs, 119 template file checksums, HTTP status + normalized HTML hash of all 6 pages, tracking row counts, statuses, file baselines.
- `plugin/uninstall`: first run **failed** (`43` #48, fixed). After the fix: snapshot written (no licence data), all plugin tables and its project config gone, site identical.
- `plugin/install`: snapshot restored and renamed, tracking/statuses/baselines identical to before, `matrixFieldUid` back in `project.yaml`, `project-config/diff` clean. Signature columns NULL (by design).
- Reinstall + Repair of Section packages, Theme, Template: no duplicate entry types/fields/sections/templates, pages unchanged. Kit Reinstall overwrote site content (`43` #49, fixed: now refused). `library/updates`: none available (site on the latest versions; publishing one would change Commerce24 data).
- No snapshot: reconcile set the 6 blocks `enabled`, recreated the 6 baselines with identical checksums and the page builder setting; `--site-content` brought Theme/Templates/Kit back. Same reinstalls: no duplicates. Lost: package versions (11), dependencies (31), 3 of 6 shared resources - all filled back in by `library/restore <set-aside snapshot>` afterwards, which then matched the original exactly.

**Author site (rp-craft)**, without uninstalling it:
- `reconcile --dry-run` and `restore --dry-run` on the live site: 0 changes (all tracking present).
- In one transaction **rolled back** at the end: all 14 tracking tables emptied (the state after reinstall), then `reconcile` dry run (27 Section packages → `enabled`, 27 baselines, a note that their import links come only from a snapshot), then `restore()` from a copy: every table's row count back to the original (109 packages, 361 versions, 375 dependencies, 458 publications, 27 files, 27 import sources, 17 shared resources), no orphan `packageId`, statuses/creators identical, baselines identical by handle/path/checksum. Second run: 0 restored.
- `restoreAfterInstall()` end to end (rolled back): restored, renamed the snapshot `.restored-…`; a package missing from the Library skipped with its version row.
- Snapshot with a newer `schemaVersion`, and corrupt JSON: error, file left.
- Settings resolution against empty stored settings: UID → field; changed UID → by handle (flagged); field gone → flagged, not set.
- Unit: `tests/unit/services/library/TrackingSnapshotTest.php` (settings filter, exclusions, table order, every table `Install` creates is snapshotted or excluded, status check).

## 8. Known gaps

- **Not exercised**: the CP uninstall/install buttons (the console path runs the same `Plugins` service methods), a Library update to a newer version after a restore, and an uninstall on the author site.
- Without a snapshot, Themes, Templates and Starter Kits are matched only with `--site-content` (§5), package versions/dependencies/publications are lost (updates still work: they compare the catalog with `site7_packages.version`), and **import links aren't recreated**: a Library package shipped to another site carries the same `importedFrom` block UID, so "imported here" can't be inferred. Reconcile reports them; without the link, Remove treats the block as generated (usage-checked delete, `12` §10). `creatorId` is also lost, which Commerce24's `syncEntitlements()` uses to leave locally authored packages alone.
- A package disabled by a plan downgrade comes back `disabled` without its `entitlementRemovableOn` date, so it isn't re-enabled automatically when the plan allows it again; Enable it by hand.
- Restored statuses don't pass the licence gate (`isLicensed()`): they describe what's already on the site. Commerce24's entitlement sync still disables unentitled `enabled` packages as before.
- `installedFiles` written by `LibraryUpdater::applySection()` use `_blocks/<handle>.twig` while install uses `templates/_blocks/<handle>.twig` (pre-existing); restore copies rows as they are, reconcile uses the install form.

## 9. For future work

- A new plugin table must go into `TrackingSnapshot::TABLES` (with its FKs and unique keys, parents first) or `EXCLUDED_TABLES` - `TrackingSnapshotTest` fails otherwise. A new licence column on `site7_packages` goes into `EXCLUDED_PACKAGE_COLUMNS`.
- Bump `SCHEMA_VERSION` only for a change older code can't read; restore refuses newer snapshots. Column additions/removals need no bump (columns are intersected).
- Don't move the restore into `afterInstall()` itself (§2).
