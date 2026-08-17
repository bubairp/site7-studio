# 24 — Licensing and Commerce

## 1. Purpose

Document the licensing, entitlement, subscription, and package-signing extension points under `src/services/commerce/`.

## 2. What It Does

A cluster of services handling license activation/validation, feature gating, plans/subscriptions, and commerce-driven package install/update tracking — all mediated through `CommerceClient` (Guzzle HTTP) against a Commerce24 backend.

## 3. Current Status

**Implemented** for the license/plan/subscription/feature-gate CRUD and event-dispatch layer. **Package signing is an explicit, intentional stub** (see §10) — architecture is prepared but cryptographic signing itself is NOT implemented.

## 4. Architecture

```
CommerceController (CP actions)
   ↓
LicenseService ──dispatches──> LicenseActivatedEvent / LicenseDeactivatedEvent /
   │                            BeforeLicenseValidationEvent / AfterLicenseValidationEvent
   ↓ (via CommerceClient)
FeatureGateService (implements FeatureGateInterface)
PlanService ──dispatches──> PlanChangedEvent
SubscriptionService ──dispatches──> SubscriptionChangedEvent
commerce/PackageService ──dispatches──> PackageInstalledEvent/PackageRemovedEvent/PackageUpdatedEvent
commerce/UpdateService ──dispatches──> UpdatesAvailableEvent
DownloadService

PackagePublisherService ──always calls──> NullPackageSigner (no-op stub)
```

## 5. Execution Flow

1. `LicenseService::activate()`/`deactivate()`/`refresh()`/`validateLicense()`/`transfer()` — implements `LicenseProviderInterface`. Results cached under key `'site7-studio.commerce24.license'`.
2. `validateLicense()` first dispatches `BeforeLicenseValidationEvent`, which supports short-circuiting (`$before->shortCircuited`/`$before->isValid`) — a listener can pre-empt the actual network validation call. After the real check, `AfterLicenseValidationEvent` is dispatched.
3. `FeatureGateService` (implements `FeatureGateInterface`) — gates CP/feature availability based on current plan/license state.
4. `PlanService`/`SubscriptionService` — plan upgrade/downgrade, subscription renew/cancel, each dispatching a change event on success.
5. `commerce/PackageService` — tracks commerce-driven install/remove/update state distinct from the core `PackageManagerService` (this is the entitlement-tracking side, not the file-lifecycle side).
6. `commerce/UpdateService` — checks for available package updates against Commerce24, dispatches `UpdatesAvailableEvent`.
7. `PackagePublisherService` calls `NullPackageSigner::sign()` unconditionally during publish — always returns `null` (no signature attached), since `isEnabled()` is hardcoded `false`.

## 6. Important Classes

**`LicenseService`** — `src/services/commerce/LicenseService.php`, implements `LicenseProviderInterface` (`src/interfaces/LicenseProviderInterface.php`: `getLicense()`, `activate()`, `deactivate()`, `refresh()`, `validateLicense()`, `transfer()` — named `validateLicense()`, not `validate()`, specifically to avoid colliding with `craft\base\Component`/`Model`'s own `validate($attributeNames, $clearErrors)` method signature).
**`FeatureGateService`** — `src/services/commerce/FeatureGateService.php`, implements `FeatureGateInterface`.
**`PlanService`** — `src/services/commerce/PlanService.php`.
**`SubscriptionService`** — `src/services/commerce/SubscriptionService.php`.
**`commerce/PackageService`** — `src/services/commerce/PackageService.php`.
**`commerce/UpdateService`** — `src/services/commerce/UpdateService.php`.
**`DownloadService`** — `src/services/commerce/DownloadService.php`.
**`CommerceClient`** — `src/services/commerce/CommerceClient.php`.
**`PackageSignerInterface`** — `src/interfaces/PackageSignerInterface.php`: `isEnabled()`, `sign(string $s7pkgPath): ?string`, `verify(string $s7pkgPath, ?string $signature): bool`.
**`NullPackageSigner`** — `src/services/publishing/NullPackageSigner.php` — the ONLY implementation. `isEnabled()` false, `sign()` returns `null`, `verify()` always `true`.
**Commerce models** — `src/models/commerce/{CommerceApiException,CustomerInfo,LicenseInfo,PlanInfo,SubscriptionInfo}.php`.
**`CommerceController`** — `src/controllers/CommerceController.php`.

## 7. Data Model

No dedicated commerce-specific database table — license/plan/subscription state is fetched live from Commerce24 (or cached transiently) rather than persisted locally in a Craft table.

## 8. Filesystem Impact

None — this subsystem is entirely API/cache-state driven.

## 9. Events

See table in `27_EVENTS_AND_HOOKS.md`. All dispatched via the shared `EventDispatcher::dispatch()` helper (`src/events/EventDispatcher.php`), which wraps `yii\base\Event::trigger()`.

## 10. Validation and Safety

**Package signing is a deliberate no-op, not a bug**: `NullPackageSigner` exists specifically as a prepared extension point — its own scope note (re-verified in code) states the architecture is ready for real cryptographic signing but that signing itself was explicitly deferred, not implemented. `verify()` always returning `true` means the current build performs NO integrity verification of installed/imported packages beyond the checksum system already covered in `06_PACKAGE_ARCHITECTURE.md`/`10_PACKAGE_IMPORT.md` — checksums detect corruption/tampering-by-accident, not cryptographic authenticity.

**`BeforeLicenseValidationEvent` short-circuit**: allows a listener to substitute its own validation result without a network call — an intentional extension point, not currently used by any in-plugin listener found during research.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| License validation attempted with no network/Commerce24 unreachable | Surfaces as a `CommerceApiException` (model exists specifically for this) |
| Package installed with a `null` signature | Accepted unconditionally — `verify()` always returns `true` regardless of signature presence |
| Feature-gated action attempted without entitlement | Blocked by `FeatureGateService`, not by `LicenseService` directly |

## 12. Developer Change Guide

If implementing real package signing: replace `NullPackageSigner` with a real `PackageSignerInterface` implementation and update its registration — `PackagePublisherService` already calls the interface, not the concrete `NullPackageSigner` class, by design, so this is meant to be a drop-in replacement.

If adding a new commerce-gated feature: use `FeatureGateService`, do not hand-roll a new entitlement check.

## 13. Related Features

`23_MARKETPLACE_ARCHITECTURE.md`, `09_PACKAGE_BUILD_AND_EXPORT.md` (signing hook lives in the publish flow), `27_EVENTS_AND_HOOKS.md`.

## 14. Known Limitations

Package signing is architecturally prepared but not implemented (§10) — document explicitly under `43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md` as well, since this is a real, confirmed gap rather than a misunderstanding.
