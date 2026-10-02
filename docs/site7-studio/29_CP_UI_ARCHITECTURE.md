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
