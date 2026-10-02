# 23 — Marketplace Architecture

## 1. Purpose

Document how packages are listed, fetched, and published across two parallel repository backends — a always-available local file-based repository and an HTTP-based Commerce24 repository.

## 2. What It Does

`MarketplaceService` registers and coordinates repository implementations of `MarketplaceRepositoryInterface`, merging their catalogs and dispatching installs to the correct backend.

## 3. Current Status

**Implemented** (Local repository fully functional; Commerce24 repository functional but self-degrades to an empty catalog when unconfigured — see §10).

## 4. Architecture

```
MarketplaceRepositoryInterface
   getHandle() / getName() / listAvailablePackages(): MarketplaceListing[] / fetchPackage($handle, $version)
        ↑                                    ↑
LocalMarketplaceRepository        Commerce24MarketplaceRepository
   handle 'local'                     handle 'commerce24'
   storage/site7-studio/              wraps CommerceClient (Guzzle)
   marketplace-repo/*.s7pkg           GET /marketplace/catalog
   glob() + readEntry()               GET /marketplace/download/{handle}
   (bundle-manifest.json)             degrades to [] if !client->isConfigured()
        \                                    /
         \                                  /
              MarketplaceService::init()
              registers both unconditionally, always
                    ↓
              getCatalog() — merges listings from all registered repos
              installFromRepository($repoHandle, $handle) — gates Commerce24
                 installs behind commercePackages->isEntitled($handle)
```

## 5. Execution Flow

1. `MarketplaceService::init()` registers `LocalMarketplaceRepository` then `Commerce24MarketplaceRepository` — both always registered; no config toggle disables registration itself.
2. `getCatalog()` — calls `listAvailablePackages()` on every registered repository, merges results. Commerce24's call returns `[]` immediately if `CommerceClient::isConfigured()` is false — this is how "Commerce not set up" is handled, not by hiding the repository entirely.
3. `installFromRepository($repositoryHandle, $handle)` — resolves the repository by handle, calls `fetchPackage()`, then routes into the same `PackageImportService::importPackage()` used by manual `.s7pkg` upload (`10_PACKAGE_IMPORT.md`) — the marketplace is a package-acquisition FRONT END, not a separate install mechanism.
4. Local repository's `fetchPackage()` reads directly from its own `storage/site7-studio/marketplace-repo/` directory (no network).
5. Commerce24's `fetchPackage()` calls `CommerceClient` → `GET /marketplace/download/{handle}`, expects a `contentsBase64` JSON envelope, decodes and caches to `storage/site7-studio/commerce24-cache/`.

## 6. Important Classes

**`MarketplaceService`** — `src/services/MarketplaceService.php`. Methods: `init()`, `registerRepository()`, `getCatalog()`, `installFromRepository()`, `recordVersion()` (`17_PACKAGE_VERSIONING.md`), `syncDependencyRecords()` (`25_DEPENDENCIES_AND_SHARED_RESOURCES.md`).
**`MarketplaceRepositoryInterface`** — `src/interfaces/MarketplaceRepositoryInterface.php`.
**`LocalMarketplaceRepository`** — `src/repositories/marketplace/LocalMarketplaceRepository.php`.
**`Commerce24MarketplaceRepository`** — `src/repositories/marketplace/Commerce24MarketplaceRepository.php`.
**`CommerceClient`** — `src/services/commerce/CommerceClient.php`, implements `CommerceClientInterface` (`src/interfaces/CommerceClientInterface.php`), Guzzle-based HTTP.
**`MarketplaceController`** — `src/controllers/MarketplaceController.php`. Actions: `actionIndex`, `actionExport`, `actionImportUpload`, `actionImportInstall`, `actionImportCancel`, `actionUpdatePackage`, `actionInstallFromRepository`, `actionReinstallPackage`, `actionRepairPackage`.

## 7. Data Model

No dedicated marketplace table — reads/writes `site7_packages`/`site7_package_versions` via the same import/version machinery as any other install path.

## 8. Filesystem Impact

**Read**: `storage/site7-studio/marketplace-repo/*.s7pkg` (local repo).
**Created**: `storage/site7-studio/commerce24-cache/*` (Commerce24 downloaded packages, cached).
**Never touched**: `templates/`, `packages/{handle}/` outside the normal import/install pipeline shared with every other package source.

## 9. Events

None dispatched by `MarketplaceService` directly — downstream `PackageImportService::importPackage()` may dispatch its own events (`10_PACKAGE_IMPORT.md`).

## 10. Validation and Safety

**"Degrade instead of gate" pattern**: an unconfigured Commerce24 connection doesn't hide the Commerce24 tab or throw — `listAvailablePackages()` simply returns an empty catalog, so the CP shows "no packages available" rather than an error. This was a deliberate design choice re-verified directly in `Commerce24MarketplaceRepository`'s code.

**Entitlement gate**: `installFromRepository()` checks `commercePackages->isEntitled($handle)` before installing from Commerce24 — Local repository installs have no such gate (anything present locally is installable).

**Caching**: `CommerceClient` caches GET responses via `Site7Studio::getInstance()->cache->getOrSet()` tagged `'commerce24'`; any mutating request invalidates that tag afterward — prevents serving stale catalog data after a purchase/change.

## 11. Failure Scenarios

| Scenario | Behavior |
|---|---|
| Commerce24 not configured (no API key/endpoint) | `listAvailablePackages()` returns `[]`, no error surfaced |
| Commerce24 configured but unreachable | Guzzle exception — not specifically caught at this layer per the research pass; surfaces as a request failure |
| `installFromRepository('commerce24', $handle)` for a non-entitled package | Blocked by the entitlement check before any fetch attempt |
| Local repository archive missing/corrupted | `fetchPackage()` would fail at the zip-read step |

## 12. Developer Change Guide

If adding a third repository backend: implement `MarketplaceRepositoryInterface` and register it in `MarketplaceService::init()` — do not special-case a new backend inside `getCatalog()`/`installFromRepository()`, which are already backend-agnostic.

## 13. Related Features

`10_PACKAGE_IMPORT.md`, `24_LICENSING_AND_COMMERCE.md`, `26_BACKUP_AND_RECOVERY.md` (shares the `marketplace-repo/` directory).

## 14. Known Limitations

No confirmed retry/backoff logic around Commerce24 HTTP failures.
