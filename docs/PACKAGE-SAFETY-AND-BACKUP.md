# Package Deletion Safety & Local Repository Auto-Backup

## Background

On 2026-07-30, deleting 10 demo Library packages via `PackageManagerService::deletePackage()`
also deleted Craft resources those packages had originally created but which had since
become shared — 8 fields (including the widely-used `blockStyle` field) and 6 entry
types, breaking 211 entries across the site. `removePackageResources()` had no concept
of "is this resource still used by something else" before deleting it.

The site was fully recovered (fields/entry-types restored via `dateDeleted` clear +
`project-config/rebuild`, verified 211 broken → 0 broken across 885 entries), and the
underlying gap was closed with two changes, plus a third, independent safety net.

## 1. `removePackageResources()` now checks usage before deleting

`src/services/CraftResourceService.php`

Before deleting any field or entry type, it now checks:

- **Entry types**: `Entry::find()->typeId($id)->status(null)->count()` — if any entries
  (of any status) still use the type, it is skipped, not deleted.
- **Fields**: `Craft::$app->getFields()->findFieldUsages($field)` (official Craft API) —
  if the field appears in any other field layout, it is skipped, not deleted.

Anything skipped is collected and surfaced back to the user as a CP notice after
deletion: *"Package deleted. Some of its Craft resources were left in place because
they are still used elsewhere: ..."* This means deleting a package can no longer
silently take down resources another package/entry still depends on.

## 2. Shared Resources delete button is usage-aware

`src/templates/library/shared-resources.twig`

The Shared Resources registry (`site7-studio/library/shared-resources`) lists Craft
resources intentionally reused across packages. The delete button there now reads the
same `usageCount` the server-side delete action already gates on:

- `usageCount == 0` → real, enabled delete button (still asks for confirmation).
- `usageCount > 0` → the button is rendered **disabled**, with a title explaining how
  many packages/resources still reference it. There is no dead-end click-then-error;
  the UI reflects the true state up front.

## 3. Local Repository auto-backup (new safety net, independent of the above)

Even with the checks above, a package's own resources (the ones nothing else uses)
are still deletable by design — that's what "delete" means. To make that reversible,
every package now backs itself up automatically, before you ever need it:

**When it runs:**
- Every time a new package is created (`PackageAuthoringService::createPackage()`)
- Every time an existing Craft resource is imported as a package (any "Import Existing
  Section/Page/Website/Entry Type" flow — all of these dispatch `ResourceImportedEvent`)

**What it does** (`src/services/support/PackageBackupService.php`, wired via
`src/events/subscribers/PackageBackupSubscriber.php`):
1. Reuses the existing `PackageExportService::exportPackage($handle, true)` to build a
   normal, self-contained `.s7pkg` (dependency closure + checksums — nothing new here).
2. Drops it into `storage/site7-studio/marketplace-repo/` — the same folder the
   Marketplace's **Repository** tab already reads from (`LocalMarketplaceRepository`).
3. Deletes any previous backup for that same package handle first (verified via each
   candidate's own `bundle-manifest.json` → `rootHandle`, not just filename prefix
   matching, to avoid e.g. `hero-banner` deleting `hero-banner-2`'s backup). Only the
   **latest** backup per package is kept — no unbounded accumulation.

A failed backup only logs a warning (`Craft::warning`) and never blocks the
create/import it's piggybacking on.

No new UI, no new database table, no new admin action — this is a thin service plus
one event subscription reusing 100% pre-existing export/repository infrastructure.

## How to restore a deleted package

1. Go to **Site7 Studio → Marketplace → Repository**.
2. Find the package by handle in the **Local Repository** list (it's there
   automatically if it was ever created/imported after this feature shipped).
3. Click **Install**.
4. This runs the normal `MarketplaceService::installFromRepository()` →
   `PackageImportService::importPackage()` path (`overwriteConflicts: true`,
   `install: true`, `enable: true`) — the same code path used for any other
   marketplace install. The package reappears in the Library as **Enabled**.

This was verified live end-to-end twice: create → confirm it appears in Local
Repository → delete via the Library card's delete button → confirm gone → Install
from Repository → confirm it's back and Enabled.

## How to permanently delete a package (including its backup)

Deleting a package from the Library (or via the Shared Resources page) removes the
live package but **not** its `.s7pkg` backup in the Local Repository — that's the
point, it's what makes the delete reversible.

There is currently **no UI button** to remove a `.s7pkg` from the Local Repository
itself. To truly purge a package (no way to restore it afterward), the `.s7pkg` file
must be deleted directly from
`storage/site7-studio/marketplace-repo/<handle>-<version>-<timestamp>.s7pkg` on the
server/DDEV container. A "Remove from Repository" button for the Marketplace →
Repository tab has been proposed but not yet built — pending a decision on whether
it's worth adding.

## Status

- Files 1 and 2 above (the safety checks + button) are **committed**.
- The auto-backup feature (`PackageBackupService.php`, `PackageBackupSubscriber.php`,
  and the two call sites in `ImportServiceProvider.php` /
  `PackageAuthoringService.php`) is implemented and live-tested, but **not yet
  committed**, pending confirmation.
