# 52. Library Distribution through Commerce24

## 1. Purpose

The Library (Section v2 `14` §15, Theme `49`, Template v2 `50`, Library Starter Kit `51`) is authored on the dev site (rp-craft) and sold through Commerce24. Customers' sites download only what they need, signed, with Commerce24 deciding who may download what. A customer's fresh Craft install with Site7 Studio starts with an **empty Library**.

## 2. Flow

```
rp-craft                                   Commerce24                        customer site (empty Library)
library/publish ── one archive per ──> POST /marketplace/publish
                   package + metadata      stores archive + listing
                                           GET /marketplace/catalog <─────── Install screen / library/catalog
                                                                             Check: kit -> Theme + Templates -> Sections,
                                                                             entitlements, Craft version, fresh site
                                           GET /marketplace/download/{h} <── Install job: download missing packages,
                                           (signs, refuses unentitled)       verify signature, put in Library
                                                                             then the normal kit install (51 §5)
```

## 3. Author side (`LibraryDistribution::publish()`)

`php craft site7-studio/library/publish [handle,handle]` publishes every distributed Library package (`libraryHandles()`: section with a v2 `schema.json`, theme, template with `template.json`, starter-kit with `starter-kit.json`), or the ones named:

- **Each package alone** (`PackageExportService::exportPackage($handle, false)`). Its requirements are separate listings, so a site downloads each package once, and only if it needs it. A whole-kit archive would be 250 MB+.
- **Metadata** sent with each package (`metadata()`):
  - `name`, `description`, `category`, `pricingType`;
  - `requires`, limited to package keys: `themes`, `templates`, `sections`, `patterns`;
  - `formatVersion`;
  - `library`: the package's own `theme.json` / `template.json` / `starter-kit.json`, without `envKeys`.
- The publish is recorded in publish history. Exported archives stay in `storage/site7-studio/exports/` (archives are immutable, `17`).

**Prices.** `php craft site7-studio/library/pricing <handle> <free|premium|private|enterprise>` sets `pricingType` in `manifest.json`. The Theme, Template and kit builders keep an existing `pricingType` on rebuild (`ThemeBuilder::existingPricingType()`). Publish the package again for Commerce24 to sell it that way.

**rp-craft (2026-10-03):** 97 packages (27 Sections, 1 Theme, 68 Templates, 1 kit), 238 MB, published in 56 s.

## 4. Customer side

- **`catalog()`** reads `GET /marketplace/catalog`, keyed by handle. It is cached like every API GET (5 min, invalidated by any POST/PUT through the plugin).
- **`closure($handle, $catalog)`** returns the package plus everything it requires, transitively, and lists any handle missing from the catalog.
- **`check($kitHandle)`** fails on any of these:
  - the kit isn't a catalog Starter Kit;
  - a requirement is missing from the catalog;
  - a package has `entitled: false`;
  - the kit's `craftVersion` differs from this site's.

  It returns the packages to download and their total size.
- **`download($handles)`**, for each package not in the Library:
  1. `Commerce24MarketplaceRepository::fetchPackage()`;
  2. `PackageImportService::validatePackage($path, true)`: a signature is **required**, and checksums are verified (`47`);
  3. `importPackage(['install' => false])` puts it in the Library and records `signatureStatus`, `signatureKeyId` and `verifiedPricingType`;
  4. the downloaded archive is deleted.

  It stops at the first failure.

**Kits** (`KitInstaller`, `51`):
- `validateKit()` on a kit that isn't in the Library runs `validateRemoteKit()`: `check()` plus the fresh-site check. The Theme's own checks run after download.
- `installKit()` downloads first (logged per package in the job), checks the kit again locally, then installs as in `51` §5.
- The Install screen lists Commerce24's Library kits that aren't in the Library (`remoteKits()`): source, price, "Not in your plan". **Check** shows the download count and size.
- Console: `library/catalog`, `library/download <handle>` (download only), `starter-kit/validate|install <handle>`, which work the same for Commerce24 kits.

**Licence gate** (`commerce/PackageService::canInstallOrEnable()`, `24` §10): it still applies when packages are installed. A downloaded package counts as paid by its **signed** `verifiedPricingType`. Since 2026-10-03 a free Commerce24 package installs without an active plan; it used to need one.

## 5. Commerce24 API (contract for the Laravel app)

All requests send `Authorization: Bearer <api key>`, `X-Site7-Environment` and `X-Site7-Store`.

**`POST /marketplace/publish`**. Body: `{handle, type, version, metadata, contentsBase64}`, where `metadata` is as in §3. Store the archive as a file, not in a database column (Templates are tens of MB). Response: `{id, listingUrl}`.

**`GET /marketplace/catalog`**. Response: `{packages: [...]}`, one entry per published package:

```json
{"handle": "rp-craft-starter-kit", "type": "starter-kit", "version": "1.0.0",
 "checksum": "<sha256 of the archive>", "size": 16494,
 "name": "RP Craft", "description": "…", "pricingType": "premium",
 "requires": {"themes": ["rp-craft-theme"], "templates": ["template-contact", "…"]},
 "metadata": {"formatVersion": 2, "library": {"craftVersion": "5.10.8.1", "theme": "rp-craft-theme", "templates": 68, "content": {…}}},
 "entitled": false}
```

`entitled` is computed **for the requesting account**. It is true for free packages; otherwise the package must be purchased or in the current active plan.

**`GET /marketplace/download/{handle}`**. The server must enforce `entitled` (paid and not entitled → `403 {"error": …}`; unknown handle → 404). Sign the archive before serving it (`47` §4, `signature.json` over `bundle-manifest.json`). The response is one of:
- with `Accept` including `application/zip`: the raw archive, `Content-Type: application/zip` (preferred; the plugin sends this `Accept` and streams to disk);
- otherwise: `{"contentsBase64": "…"}`, the original envelope, still accepted.

**`GET /packages/entitlements`**. `free` should list every free published package; `purchased` and `premium` (plan) as before.

## 6. Mock (`rp-craft/mock-commerce24`, local only, git-ignored)

Implements §5:
- archives in `mock-commerce24/archives/<handle>.s7pkg`;
- the Business and Enterprise plans include `rp-craft-starter-kit`.

Start it inside rp-craft's web container on all interfaces, so other DDEV sites can reach it at `http://ddev-rp-craft-web:8090` (both sites are on `ddev_default`):

```
ddev exec "nohup php -d memory_limit=-1 -S 0.0.0.0:8090 mock-commerce24/router.php > /tmp/mock-commerce24.log 2>&1 &"
```

## 7. Verified (2026-10-03)

**Setup.** site7-fresh: fresh Craft 5.10.8.1 + Site7 Studio. The Library was emptied (no package files or records). `config/site7-studio.php` was set from `.env`: endpoint `http://ddev-rp-craft-web:8090`, the mock's signing public key.

**Free kit, installed from the CP** (Install → Check → Install, watched in a browser):
- 91 packages (249 MB) downloaded, all `signatureStatus = verified`;
- whole site in 94 s;
- 550 entries, 62 menu items;
- all 68 pages return 200, 57 with rp-craft's exact text; the other 11 differ only in related-list order (`51` §6).

**Paid kit** (`premium`), account on Professional:
- the mock refuses the download (403);
- `starter-kit/validate` fails with "'RP Craft' is a paid package that isn't in your plan or purchases";
- after upgrading to Business it installs (1m27s, 91 signed packages), and the kit records `verifiedPricingType = premium`.

## 8. Known limitations

- **No updates.** A newer version of an installed package isn't applied to installed sites (`MarketplaceService::checkForUpdates()` predates the Library and doesn't handle Library kinds).
- **Publishes always send the full archive** (no delta), and the archive versions recorded on publish keep piling up under `exports/`.
- **No retries.** The first failed download stops the install; packages already downloaded stay in the Library, so running the install again only downloads the rest.
- **The real Laravel Commerce24** must implement §5. The mock is not a security reference.

## 9. Important classes

`services/library/LibraryDistribution`, `console/controllers/LibraryController`, `services/commerce/CommerceClient::download()`, `repositories/marketplace/Commerce24MarketplaceRepository::fetchPackage()`, `services/starterkit/KitInstaller::validateRemoteKit()`. Tests: `tests/unit/services/library/LibraryDistributionTest`.
