# 27 — Events and Hooks

## 1. Purpose

Enumerate every custom event class in the plugin: what it carries, where it's dispatched, and who listens.

## 2. What It Does

Provides a decoupled notification mechanism (`EventDispatcher` wrapping `yii\base\Event::trigger()`) so features like automatic backup can react to imports without the importer knowing backup exists.

## 3. Current Status

**Implemented.**

## 4. Architecture

```
EventInterface (getEventName())
   ↑
BaseEvent extends yii\base\Event implements EventInterface
   (getEventName() returns static::class)
   ↑
[all newer event classes]

PackageEvent extends yii\base\Event   (older style: PackageRecord $package, bool $success=true)
   ↑
PackageInstallEvent (adds ?\Throwable $exception)

EventDispatcher extends craft\base\Component
   dispatch(EventInterface $event) → yii\base\Event::trigger(get_class($event), $event->getEventName(), $event)
   addSubscriber(EventSubscriberInterface $subscriber) → loops getSubscribedEvents()
      ([class, eventName, method] triples) → Event::on($class, $eventName, [$subscriber, $method])
```

## 5. Execution Flow

Two coexisting patterns in this codebase:
1. **`EventDispatcher`-mediated** (`src/events/EventDispatcher.php`) — used by most domain events (import, publishing, commerce). Services call `Site7Studio::getInstance()->eventDispatcher->dispatch(new SomeEvent(...))`; subscribers register via `addSubscriber()`.
2. **Raw `yii\base\Event::on()`** — used directly in `src/Site7Studio.php` for framework-level hooks (see §9 table) that don't need the plugin's own dispatch wrapper.

## 6. Important Classes

`EventDispatcher` (`src/events/EventDispatcher.php`), `EventInterface`/`BaseEvent` (`src/events/`), `PackageEvent`/`PackageInstallEvent` (`src/events/`).

## 7. Data Model

Not applicable — events are transient, in-process only.

## 8. Filesystem Impact

None directly — side effects belong to listeners, not the event system itself.

## 9. Events — full inventory

| Class | File | Key properties | Dispatched from |
|---|---|---|---|
| `PackageEvent` | `src/events/PackageEvent.php` | `PackageRecord $package`, `bool $success=true` | base class only, no direct trigger found (extension point) |
| `PackageInstallEvent` | `src/events/PackageInstallEvent.php` | + `?\Throwable $exception` | not currently dispatched (extension point) |
| `PackageExportedEvent` | `src/events/PackageExportedEvent.php` | `handle`, `path`, `bundledHandles[]` | `PackageExportService.php:102` |
| `PackageImportedEvent` | `src/events/PackageImportedEvent.php` | `rootHandle`, `summary[]` | `PackageImportService.php:226` |
| `ResourceImportedEvent` | `src/events/ResourceImportedEvent.php` | `sourceType`, `?sourceId`, `packageHandles[]`, `summary[]` | `MatrixEntryTypeImportService.php:149`, `PageImportService.php:260`, `WebsiteImportService.php:331` |
| `RegisterNavigationEvent` | `src/events/RegisterNavigationEvent.php` | (CP nav) | `CpNavigationRegistry` internal |
| `RegisterPermissionsEvent` | `src/events/RegisterPermissionsEvent.php` | (CP perms) | `CpPermissionRegistry` internal |
| `BeforeLicenseValidationEvent`/`AfterLicenseValidationEvent` | `src/events/commerce/*.php` | `isValid`, `shortCircuited` | `LicenseService.php:104` (Before), `:111` (After) |
| `LicenseActivatedEvent` | `src/events/commerce/*.php` | `license` | `LicenseService.php:69` |
| `LicenseDeactivatedEvent` | | `licenseKey` | `LicenseService.php:82` |
| `PackageInstalledEvent`/`PackageRemovedEvent`/`PackageUpdatedEvent` | | `handle` | `commerce/PackageService.php:285,298,316` |
| `PlanChangedEvent` | | | `PlanService.php:108` |
| `SubscriptionChangedEvent` | | | `SubscriptionService.php:127` |
| `UpdatesAvailableEvent` | | `updates` | `commerce/UpdateService.php:48` |
| `RepositorySelectedEvent`/`BeforePublishEvent`/`AfterPublishEvent`/`PublishFailedEvent` | `src/events/publishing/*.php` | `handle`, `repositoryHandle`, `packagePath`/`result`/`reason` | `PackagePublisherService.php:73,74,120,127` |
| `BeforeValidationEvent`/`AfterValidationEvent` | | `handle`, `result` | `PublishValidatorService.php:39,157` |
| `PackageBuiltEvent` | | | `PackageBuilderService.php:55` |
| `VersionCreatedEvent` | | | `VersionManagerService.php:83` |
| `PackageSignedEvent` | | (extension point) | not dispatched — no signer implementation calls it |

**Non-`EventDispatcher` framework hooks** (raw `yii\base\Event::on()` in `src/Site7Studio.php`):
- `craft\web\View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE` (line ~148) — injects Pattern insertion JS/`PatternMatrixBundle`.
- `craft\elements\Entry::EVENT_DEFINE_ALT_ACTIONS` (line ~177) — adds "Save as Template" alt action.
- `craft\web\UrlManager::EVENT_REGISTER_CP_URL_RULES` (line ~228) — registers all CP navigational routes (`28_CONTROLLERS_AND_ROUTES.md`).

**Subscribers** (`EventSubscriberInterface` registrations):
- `ImportServiceProvider.php:43` — `PackageBackupSubscriber` (`26_BACKUP_AND_RECOVERY.md`) listens to `ResourceImportedEvent`.
- `CpServiceProvider.php:28` — `CpSubscriber` (`src/events/subscribers/CpSubscriber.php`).

## 10. Validation and Safety

Event dispatch failures are not specifically caught by `EventDispatcher::dispatch()` — a throwing listener would propagate up to the original caller (e.g. an import action). `PackageBackupSubscriber` handles this internally by catching its OWN failures inside `PackageBackupService` rather than relying on `EventDispatcher` to isolate it (`26_BACKUP_AND_RECOVERY.md` §10).

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| A subscriber throws during handling | Propagates to the dispatching caller — no plugin-wide event-error isolation found |
| An event class has no listeners registered | No-op, `yii\base\Event::trigger()` simply finds nothing to call |

## 12. Developer Change Guide

If adding a new domain event: extend `BaseEvent` (not the older `PackageEvent`, which predates the interface and is kept only for its one still-used subclass slot), dispatch via `EventDispatcher::dispatch()`, and prefer a subscriber (`EventSubscriberInterface`) over ad-hoc `Event::on()` calls scattered through providers — matches the existing `PackageBackupSubscriber`/`CpSubscriber` pattern.

## 13. Related Features

`26_BACKUP_AND_RECOVERY.md`, `24_LICENSING_AND_COMMERCE.md`, `28_CONTROLLERS_AND_ROUTES.md`.

## 14. Known Limitations

Two coexisting event-class styles (`BaseEvent`/`EventInterface` vs. the older `PackageEvent`) — not unified, `PackageEvent` kept for backward compatibility with `PackageInstallEvent`, which itself is currently unused (extension point, no dispatch site found).
