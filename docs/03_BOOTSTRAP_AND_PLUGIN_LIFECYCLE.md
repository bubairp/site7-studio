# 03 — Bootstrap and Plugin Lifecycle

## 1. Purpose

Explain exactly what happens, in order, from Craft booting to the plugin being fully wired — so a developer can reason about initialization order bugs (e.g. "why isn't my nav item showing," "why is this service not registered yet").

## 2. What It Does

Registers ~70 named service-locator components, CP routes, event handlers, and CP navigation/permissions, all from one plugin entry class.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
Craft boot
   ↓
Migrations run (Install.php [no-op stub] + 15 timestamped migrations, if pending)
   ↓
Site7Studio::init()
   ↓
   registerServiceProviders()
   ↓
   CoreServiceProvider → EventServiceProvider → CpServiceProvider →
   LibraryServiceProvider → ImportServiceProvider → CommerceServiceProvider →
   PublishingServiceProvider     (each calls $plugin->set('name', [...]))
   ↓
Craft::$app->onInit() fires
   ↓
Site7Studio::attachEventHandlers()
   ↓
   1. Registers PatternMatrixBundle + injects window.site7Studio on every CP page
      (View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, Site7Studio.php:147-170)
   ↓
   2. Adds "Save as Template" alt-action to the Entry edit Save dropdown
      (Entry::EVENT_DEFINE_ALT_ACTIONS, Site7Studio.php:172-225)
   ↓
   3. yii\base\Event::on(UrlManager::class, EVENT_REGISTER_CP_URL_RULES, ...) — CP routes
      (Site7Studio.php:227-271)
   ↓
CpSubscriber / PackageBackupSubscriber registered against eventDispatcher
   (inside CpServiceProvider / ImportServiceProvider respectively)
   ↓
[lazy] first CP page load reads CpNavigationRegistry::getNavItems() /
   CpPermissionRegistry::getPermissions() → each fires its own event ONCE,
   memoized → CpSubscriber's handlers populate them
```

## 5. Execution Flow

1. Craft loads the plugin's migrations (if any are pending) before the plugin's own `init()` runs.
2. `Site7Studio::init()` calls `registerServiceProviders()`, instantiating and calling `->register($this)` on all 7 providers in the fixed order listed above.
3. After this call returns, every named component in `38_SERVICE_REFERENCE.md` is reachable as `Site7Studio::getInstance()->{name}`.
4. `Craft::$app->onInit()` (fired later, after Craft itself is fully booted) triggers `attachEventHandlers()`.
5. Inside `attachEventHandlers()`, the actual registration order is: PatternMatrixBundle/JS injection first, "Save as Template" alt-action second, CP routes last (see §4 diagram above — this document previously listed CP routes first, which was backwards). CP routes are registered explicitly (see `28_CONTROLLERS_AND_ROUTES.md` for the full list) — every other action is reached via Craft's default `site7-studio/<controller-id>/<action-id>` resolution.
6. The first time any CP page reads plugin navigation/permissions, `CpNavigationRegistry`/`CpPermissionRegistry` lazily fire their own internal events, which `CpSubscriber` (already registered in step 3 above, wired at provider-registration time) handles — this is what actually populates the "Site7 Studio" nav item and its permissions.

## 6. Important Classes

**`Site7Studio`**
`src/Site7Studio.php`
Responsibility: plugin entry point — bootstrap, routing, dev-mode gate.
Important public methods: `init()`, `attachEventHandlers()` (private, called via `onInit`), `isDevMode(): bool` (static), `getCpNavItem()`.
Callers: Craft's plugin loader.
Dependencies: all 7 service providers.
Side effects: registers ~70 components, CP routes, CP page JS injection.

**`PluginTrait`**
`src/base/PluginTrait.php`
Responsibility: mixes in `@property-read` typed accessors for every registered component (e.g. `$plugin->packageManager`).

**Each `*ServiceProvider`**
`src/providers/{Core,Event,Cp,Library,Import,Commerce,Publishing}ServiceProvider.php`
Responsibility: registers one cohesive group of named components via `$plugin->set('name', ['class' => X::class])` (or a closure for `libraryService`, and non-array config for a couple of simple ones like `CpNavigationRegistry`/`CpPermissionRegistry`).
Callers: `Site7Studio::registerServiceProviders()`, in the fixed order above.

## 7. Data Model

None directly — this document is about wiring, not data. See `05_DATABASE_ARCHITECTURE.md` for what the migrations create.

## 8. Filesystem Impact

**Created**: nothing at bootstrap time (migrations create DB tables, not files).
**Modified**: nothing.
**Deleted**: nothing.
**Never touched**: any package/template/frontend file — bootstrap is pure service wiring.

## 9. Events

- `RegisterNavigationEvent`, `RegisterPermissionsEvent` — self-dispatched by `CpNavigationRegistry`/`CpPermissionRegistry` the first time they're read; handled by `CpSubscriber` (see `27_EVENTS_AND_HOOKS.md`).
- `craft\services\UserPermissions::EVENT_REGISTER_PERMISSIONS`, `craft\services\Dashboard::EVENT_REGISTER_WIDGET_TYPES` — genuine Craft-core events, subscribed directly by `CpSubscriber`, bypassing the plugin's own `EventDispatcher` facade entirely.
- `craft\web\twig\variables\Entry::EVENT_DEFINE_ALT_ACTIONS`, `craft\web\View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE`, `UrlManager::EVENT_REGISTER_CP_URL_RULES` — subscribed directly inside `attachEventHandlers()`.

## 10. Validation and Safety

None applicable — bootstrap has no user input or destructive operations.

## 11. Failure Scenarios

If a service provider throws during registration, plugin initialization fails and Craft reports the plugin as broken (standard Craft plugin-load-failure behavior — no Site7-specific handling exists here).

## 12. Developer Change Guide

**If you need to add a new service**: add it in the appropriate existing `*ServiceProvider` (match its domain — package/import/commerce/publishing/CP/library/core) rather than creating an eighth provider, unless the new service is a genuinely new cohesive domain.

**If you need to add a new CP route**: add it to `attachEventHandlers()`'s route array in `Site7Studio.php` — see `28_CONTROLLERS_AND_ROUTES.md` for the existing pattern and the convention of leaving most actions unrouted (reached via default action resolution).

**If you need to add a new CP nav item or permission**: extend `CpSubscriber::onRegisterSite7Navigation()`/`onRegisterSite7Permissions()` — do not create a second nav/permission registration path.

## 13. Related Features

`27_EVENTS_AND_HOOKS.md`, `28_CONTROLLERS_AND_ROUTES.md`, `29_CP_UI_ARCHITECTURE.md`, `38_SERVICE_REFERENCE.md`.

## 14. Known Limitations

None confirmed at the bootstrap level itself.
