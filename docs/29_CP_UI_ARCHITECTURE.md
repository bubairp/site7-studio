# 29 — CP UI Architecture

## 1. Purpose

Document how Control Panel navigation and permissions are assembled, and the self-dispatched-event pattern used to keep nav/permission registration lazy and decoupled from provider registration order.

## 2. What It Does

`CpNavigationRegistry`/`CpPermissionRegistry` build the CP nav tree and permission set by dispatching `RegisterNavigationEvent`/`RegisterPermissionsEvent` and letting each domain area contribute its own entries, rather than one central class hard-coding the full list.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
Site7Studio::init()
   ↓
CpServiceProvider::register() registers CpNavigationRegistry, CpPermissionRegistry, CpSubscriber
   ↓
CpSubscriber (EventSubscriberInterface) — reacts to lazy registration triggers
   ↓
CpNavigationRegistry::getNavItems() → dispatches RegisterNavigationEvent →
   listeners append their nav entries → merged nav tree returned to Craft's CP
CpPermissionRegistry::getPermissions() → dispatches RegisterPermissionsEvent →
   listeners append their permission entries
```

## 5. Execution Flow

1. Craft's CP asks the plugin for its nav items / registered permissions (standard `craft\base\Plugin` hooks).
2. `CpNavigationRegistry`/`CpPermissionRegistry` don't hard-code the list — they dispatch `RegisterNavigationEvent`/`RegisterPermissionsEvent` (self-dispatched events, `27_EVENTS_AND_HOOKS.md`), collecting contributions from listeners.
3. `CpSubscriber` (`src/events/subscribers/CpSubscriber.php`) is the primary listener that populates these events with the plugin's actual nav/permission entries.

## 6. Important Classes

**`CpNavigationRegistry`** — `src/services/CpNavigationRegistry.php` (or equivalent path under `src/services/`).
**`CpPermissionRegistry`** — `src/services/CpPermissionRegistry.php`.
**`CpSubscriber`** — `src/events/subscribers/CpSubscriber.php`, registered via `CpServiceProvider.php:28`.
**`RegisterNavigationEvent`**/**`RegisterPermissionsEvent`** — `src/events/*.php`.

## 7. Data Model

Not applicable.

## 8. Filesystem Impact

None.

## 9. Events

`RegisterNavigationEvent`, `RegisterPermissionsEvent` — see `27_EVENTS_AND_HOOKS.md`.

## 9a. Menu layout (2026-10-05)

The menu has two audiences: the sites that use the Library (customers), and the site the Library is authored on (rp-craft, Dev Mode).

| Menu item | Shown | Contents |
|---|---|---|
| Dashboard | always | counts, page builder (or: Install a Starter Kit / choose in Settings) |
| Library | always | Sections · Templates · Starter Kits; Shared Resources in Dev Mode only |
| Install | always | Library Starter Kits; Blueprint Starter Kits (`32`) in Dev Mode only |
| Updates | always | Library updates (`53`); Blueprint kit updates in Dev Mode only. The one place for updates |
| Account & License (`site7-studio/commerce`) | always | Overview · Plan & Subscription (Plans included) · License · Packages (Downloads included) · Account |
| Publishing | Dev Mode | Publish the Library to Commerce24 (runs `library/publish` as a background job), repositories, publish history |
| Marketplace | Dev Mode | Installed · Import · Export · Updates · Repository (.s7pkg files and repositories) |
| Site Kits | Dev Mode | Full Site Kits (`48`) |
| Settings | always | General · Commerce · System · About |

**Settings read-only states (2026-10-09).** Saving (`SettingsController::actionSave()` → `savePluginSettings()`) writes project config, so: with `allowAdminChanges` off, General and Commerce render every field disabled, show Craft's `readOnlyNotice()` plus a note that settings come from `.env` / `config/site7-studio.php` / project config through git, and hide every Save button. The action itself already refuses that case: Craft 5's `requireAdmin()` checks `allowAdminChanges` by default (403). Separately, any key `config/site7-studio.php` sets (`Settings::overriddenKeys()`, the same list `mergeWithStored()` drops) renders disabled with the effective value and "Set in config/site7-studio.php"; the API key only shows as masked. A form with no editable field has no Save. `Settings::editableKeys()` decides; Test Connection always stays available.

Removed from view, code kept: Commerce's Updates tab (`?tab=updates` redirects to Updates; its actions redirect there too), Team tab (no backend yet, `_team.twig`), and the empty Theme Settings tab. Old `?tab=plans`/`downloads`/`team` links land on the tab that now holds them. Dev Mode screens hidden from the menu still work by URL for users with the permissions.

## 10. Validation and Safety

**Why event-dispatched, not hard-coded**: allows nav/permission contributions to be added by future feature areas without modifying a central registry class — matches the plugin's general "avoid a god class" pattern seen elsewhere (e.g. `MarketplaceService`'s pluggable repository registration, §23).

## 11. Failure Scenarios

Not applicable at this document's scope.

## 12. Developer Change Guide

If adding a new CP nav item or permission: add a listener to `RegisterNavigationEvent`/`RegisterPermissionsEvent` (or extend `CpSubscriber` if it already owns the relevant domain) — do not modify `CpNavigationRegistry`/`CpPermissionRegistry` directly to hard-code a new entry.

## 13. Related Features

`27_EVENTS_AND_HOOKS.md`, `28_CONTROLLERS_AND_ROUTES.md`, `03_BOOTSTRAP_AND_PLUGIN_LIFECYCLE.md`.

## 14. Known Limitations

None confirmed.
