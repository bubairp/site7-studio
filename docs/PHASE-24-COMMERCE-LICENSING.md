# Phase 24 — Commerce & Licensing

Status: **Implemented.**

Commerce & Licensing is SITE7 Studio's integration point with **Commerce24**, the single source of truth for plans, subscriptions, licenses, purchased packages/add-ons, downloads, and updates. SITE7 Studio never duplicates this data — everything shown in this section is fetched from Commerce24 (through a cache) or derived from local install state, never stored as its own copy of business truth.

## Entry points

- CP page: `site7-studio/commerce` (`CommerceController::actionIndex`), tabs selected via `?tab=`.
- Settings: `site7-studio/settings` → Commerce tab — where the connection itself (API endpoint, store identifier, API key) is configured. This is the only place that writes `commerceApiEndpoint`/`commerceApiKey`; Account's "Connect"/"Disconnect" reuse this same settings surface (Connect links there, Disconnect clears those two fields via the same `savePluginSettings()` call `SettingsController::actionSave()` uses).

## Tabs → data → template

| Tab | Controller case | Template | Backing service(s) |
|---|---|---|---|
| Overview | `overview` | `_overview.twig` | `license`, `subscription`, `plan`, `packageManager`, `marketplace` |
| Plans | `plans` | `_plans.twig` | `plan` |
| Subscription | `subscription` | `_subscription.twig` | `subscription` |
| License | `license` | `_license.twig` | `license` |
| Packages | `packages` | `_packages.twig` | `plan`, `commercePackages`, `packageManager` |
| Downloads | `downloads` | `_downloads.twig` | `downloads` |
| Updates | `updates` | `_updates.twig` | `updates` |
| Account | `account` | `_account.twig` | `commerceClient`, `subscription` |
| Team *(beyond Phase 24's spec, kept for a future Team feature)* | `team` | `_team.twig` | `featureGate` |

## Service layout (`src/services/commerce/`)

Every service is reached through `Site7Studio`'s service locator (`CommerceServiceProvider`), never constructed directly, and depends only on `CommerceClientInterface` — never on Guzzle/HTTP itself:

- **`CommerceClient`** — the sole HTTP gateway to Commerce24. `isConfigured()` is the one check every other service makes before doing anything; it's what "offline mode" hinges on.
- **`LicenseService`** — activate/deactivate/refresh/validate/transfer.
- **`SubscriptionService`** — subscription state, upgrade/downgrade/renew/cancel, manage-portal URL, and `getCustomer()` (the connected Commerce24 account — name/email/company/portal URL).
- **`PlanService`** — plan catalog, current plan, and `refreshCurrentPlanAndSyncEntitlements()`, which both re-reads the current plan *and* reconciles installed packages against it (see below).
- **`PackageService`** (registered as `commercePackages`, deliberately not `packageManager`) — Commerce24's purchase/entitlement view (`purchased`/`free`/`premium` handle lists) layered on top of the Package Engine's own install state. Owns the disable-then-grace-period-then-delete flow for packages a downgrade drops.
- **`DownloadService`**, **`UpdateService`**, **`FeatureGateService`** — downloads/import/export history, update checks, and the plan-driven feature gate (`featureGate->allows('someFeature')`).

All GET reads go through `CacheService::getOrSet()` tagged `commerce24` (plus a per-resource tag, e.g. `commerce24-license`); any mutating request invalidates the whole `commerce24` tag so the next read is fresh.

## Offline mode

`CommerceClient::isConfigured()` is false whenever the endpoint or API key isn't set. Every commerce service checks it before calling out, and degrades to an empty/neutral result instead of throwing (`LicenseInfo(status: unlicensed)`, `SubscriptionInfo(status: none)`, empty entitlement lists, empty `CustomerInfo`) — nothing in Library, Publishing, or local package management depends on Commerce24 being reachable.

Where this shows up in the UI:
- `commerce/index.twig` shows a "Commerce24 isn't connected yet" notice above every tab when not configured.
- Overview and Account both show an explicit **Connection Status** field (`Connected` / `Not Connected`), not just the banner.
- Overview's Quick Actions swap "Upgrade Plan" for **Connect Commerce24** when disconnected.
- Account shows a **Connect Commerce24** button (→ Settings' Commerce tab) when disconnected, or **Disconnect** (clears the two connection fields) plus customer info and portal links when connected.
- `Settings::commerceOfflineFeatures` is an explicit escape hatch: feature handles listed there stay allowed even while offline (`FeatureGateService`).

## Packages tab: entitlement vs. install state

`_packages.twig` deliberately separates three things that are easy to conflate:

1. **Installed packages** — from `packageManager` (the Package Engine, local install/enable/disable state), annotated with an ownership badge (`Purchased`/`Free`/`Premium`/`Unknown`) computed against Commerce24's entitlement lists.
2. **Available to Install** — entitled (purchased or free) but not yet installed on this site; each row posts to `commerce/install-package`, which routes through `PackageService::installEntitled()` (rejects anything not actually entitled).
3. **Locked Packages** — premium and not owned; shown so the gap between "what the account could have" and "what it has" is visible, with a link to Plans. Never installable from here.

Actual install/enable/disable/update/repair/reinstall of anything already installed stays on Library/Marketplace — this tab only reflects ownership and entitlement.

Plan changes (`refreshCurrentPlanAndSyncEntitlements()`, run on every visit to Overview/Packages, and again right after an upgrade/downgrade) call `PackageService::syncEntitlements()`, which disables (never deletes) anything no longer covered and starts a 14-day (`PackageService::GRACE_PERIOD_DAYS`) grace period before it becomes removable via `commerce/remove-pending-package`. A plan change that brings a disabled package back into scope auto-re-enables it, but only if this same mechanism was what disabled it in the first place — a package the site owner disabled manually is left alone.

## Extension points

- `LicenseProviderInterface`, `SubscriptionProviderInterface`, `PlanService`'s public surface, `PackageProviderInterface`, `CommerceClientInterface` — swap any of these for a different backend without touching `CommerceController` or any template.
- `events/commerce/*` — `LicenseActivatedEvent`, `LicenseDeactivatedEvent`, `SubscriptionChangedEvent`, `PlanChangedEvent`, `PackageInstalledEvent`/`PackageRemovedEvent`/`PackageUpdatedEvent`, `UpdatesAvailableEvent`, `Before/AfterLicenseValidationEvent` — dispatched via `Site7Studio::getService('eventDispatcher')`, the same `EventDispatcher` every other domain in this plugin uses.
- Permissions (`CpSubscriber::onRegisterSite7Permissions`): `manageCommerce`, `manageLicense`, `manageSubscription`, `managePackages`, `manageUpdates`, `manageTeam` gate the corresponding actions; `manageCommerce` specifically gates connect/disconnect.
