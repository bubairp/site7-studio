# 55. Connecting a Site to Commerce24

How to connect a Craft site that runs Site7 Studio to Commerce24, the backend that holds the Site7 Library, plans, licences and purchases. It covers the author site (publishes the Library) and customer sites (install and update from it), locally and in production.

Contract: `52` §5. Signing: `47`. Local Commerce24 app: `~/my-project/commerce24` (its README).

## 1. What a site needs

| Setting | `.env` variable | Example (local) |
|---|---|---|
| API endpoint | `COMMERCE24_API_ENDPOINT` | `http://ddev-commerce24-web/api` |
| API key (one per site) | `COMMERCE24_API_KEY` | `c24_…`, shown once when it's issued |
| Store identifier: the site's domain | `COMMERCE24_STORE` | `site7-fresh.ddev.site` |
| Environment | `COMMERCE24_ENVIRONMENT` | `development` / `production` |
| Signing key id that site trusts | `COMMERCE24_SIGNING_KEY_ID` | `c24-local` |
| Signing public key | `COMMERCE24_SIGNING_PUBLIC_KEY` | base64, from Commerce24 |

The site reads them through `config/site7-studio.php`. Copy it from rp-craft:

```php
<?php
use craft\helpers\App;

$signingKeyId = App::env('COMMERCE24_SIGNING_KEY_ID');
$signingPublicKey = App::env('COMMERCE24_SIGNING_PUBLIC_KEY');

return array_filter([
    'commerceApiEndpoint' => App::env('COMMERCE24_API_ENDPOINT') ?: null,
    'commerceApiKey' => App::env('COMMERCE24_API_KEY') ?: null,
    'commerceStoreIdentifier' => App::env('COMMERCE24_STORE') ?: null,
    'commerceEnvironment' => App::env('COMMERCE24_ENVIRONMENT') ?: null,
    'trustedSigningKeys' => $signingKeyId && $signingPublicKey ? [$signingKeyId => $signingPublicKey] : null,
], fn($value) => $value !== null);
```

A config file overrides the CP settings (Settings → Commerce), so each environment keeps its own connection, and secrets stay out of project config. Settings → Commerce shows the keys it sets as read-only, with the values in use (API key masked). On a server with `allowAdminChanges` off, the whole Settings screen is read-only: change `.env` there, not the CP (Save would write project config, which such servers can't write; `29` §9a).

## 2. Step by step (local)

### 2.1 Start Commerce24

```bash
cd ~/my-project/commerce24
ddev start
```

The admin is at https://commerce24.ddev.site/admin. Use the password in its `.env` (`C24_ADMIN_PASSWORD`), or a one-time link from `ddev exec php artisan commerce24:admin-link`.

### 2.2 Make sure the customer exists

In the admin, under Customers, use the existing account or create a new one (it gets one licence key). The account decides the plan, licences and purchases of every site connected with its keys.

### 2.3 Issue an API key for the site

In the admin, open the customer, then **API keys**, then **Create API key**:
- **Site name:** anything readable, e.g. `site7-fresh`.
- **Site domain:** the site's own domain, e.g. `site7-fresh.ddev.site`. Licences are activated for this domain.
- **May publish:** only for the author site (rp-craft). Customer sites never publish.

Copy the key. It's shown once; only a hash is stored.

From the command line:

```bash
ddev exec php artisan commerce24:api-key demo@customer.local "site7-fresh" --domain=site7-fresh.ddev.site
ddev exec php artisan commerce24:api-key author@site7.local "rp-craft (author)" --domain=rp-craft.ddev.site --publisher
```

### 2.4 Get the signing public key

```bash
grep -E '^C24_SIGNING_(KEY_ID|PUBLIC_KEY)=' ~/my-project/commerce24/.env
```

Or call `GET /api/signing/public-key` with any valid API key. Every download is signed with this key, and the site refuses archives it can't verify (`47`).

### 2.5 Fill in the site's `.env`

```
COMMERCE24_API_ENDPOINT=http://ddev-commerce24-web/api
COMMERCE24_API_KEY=c24_xxxxxxxx
COMMERCE24_STORE=site7-fresh.ddev.site
COMMERCE24_ENVIRONMENT=development
COMMERCE24_SIGNING_KEY_ID=c24-local
COMMERCE24_SIGNING_PUBLIC_KEY=xxxxxxxx=
```

Why `http://ddev-commerce24-web` and not `https://commerce24.ddev.site`: Craft calls Commerce24 from inside its own container. Inside a container, `*.ddev.site` points at the container itself, while `ddev-commerce24-web` is Commerce24's web container on DDEV's shared network. Links Commerce24 sends back for people to open (manage subscription, portal) always use its public address, `https://commerce24.ddev.site`.

Clear caches afterwards: `ddev craft clear-caches/all`.

### 2.6 Check the connection

1. **Site7 Studio → Settings → Commerce → Test Connection** should say "Connected to Commerce24".
2. **Account & License → License:** enter a licence key from the customer's account and click **Activate**. The status becomes Active, with the site's domain.
3. **Account & License → Overview** should show Connected, the plan, the licence and subscription status, and the package counts.

From the command line:

```bash
ddev exec curl -s http://ddev-commerce24-web/api/ping           # {"status":"ok",...}
ddev craft site7-studio/library/catalog                          # packages Commerce24 offers this site
```

## 3. The author site (rp-craft)

- It uses a key with **may publish**.
- Publish with **Publishing → Publish Library** (Dev Mode), or `ddev craft site7-studio/library/publish`. Every changed package goes up as a new version, and unchanged ones are skipped. Each version is stored and stays downloadable.
- **Prices:** set them in the package (`ddev craft site7-studio/library/pricing <handle> premium`) and publish again. The Commerce24 admin can override a price afterwards.

## 4. A customer site

1. Start from a fresh Craft that has the **same Craft version** as the author site, then install Site7 Studio (§5). The Library starts empty: the plugin ships code only. When Composer installed the plugin (in `vendor/`), the Library is `storage/site7-studio/packages/` (`06` §8), so updating the plugin with Composer keeps it.
2. Connect it (§2) and activate a licence.
3. **Install → Library Starter Kits:** pick a kit, click **Check**, then **Install**. The kit, its Theme and its Templates are downloaded from Commerce24 (each signature-verified) and installed as a background job.
4. **Updates** lists newer Library versions; update them there. Local edits are kept (`53`).
5. **Paid packages** (`pricingType` not free) download only when they're in the active plan or bought. Otherwise the plugin shows "Not in your plan" and Commerce24 answers 403.

## 5. Adding the plugin to a fresh Craft site (local)

**From its Git repository** (what a customer does; `.claude/new-site-from-repo.sh` in rp-craft scripts it):

```bash
composer config repositories.site7-studio vcs https://github.com/bubairp/site7-studio.git
composer require site7/studio:dev-main
php craft plugin/install site7-studio
```

Then create `config/site7-studio.php` (§1) and fill in `.env` (§2.5). The Theme brings the plugin folders it needs (`49` §2d).

**From rp-craft's folder** (the earlier local setup):

```bash
mkdir -p plugins && rsync -a --exclude .git --exclude '/packages/*' --exclude /tests ~/my-project/rp-craft/plugins/site7-studio/ plugins/site7-studio/
mkdir -p plugins/site7-studio/packages
ddev composer config repositories.site7-studio path plugins/site7-studio
ddev composer require 'site7/studio:*'
ddev craft plugin/install site7-studio
```

The leading `/` in `'/packages/*'` matters: without it rsync also skips `src/models/packages/`, the package model classes, and Library installs then fail with `Class "site7\studio\models\packages\PackageManifest" not found`.

The RP Craft Theme also expects these plugin folders, copied the same way: `plugins/ai-chat`, `plugins/htmlsitemap` and `plugins/payment-gateway` (its composer path repositories).

## 6. Production

- **Endpoint:** the real Commerce24 URL over HTTPS, e.g. `https://commerce24.example.com/api`.
- **One API key per site**, created in Commerce24 for that customer, with the site's real domain. Keep it only in `.env` or the hosting secrets, never in project config.
- **Signing key:** Commerce24's production key id and public key. Once a production key is built into the plugin (`Ed25519PackageSigner::BUILT_IN_KEYS`), `COMMERCE24_SIGNING_*` isn't needed.
- `COMMERCE24_ENVIRONMENT=production`.

## 7. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Test Connection: "Could not reach Commerce24: cURL error 6/7" | Wrong endpoint, or Commerce24 isn't running. Locally, run `ddev start` in `commerce24` and use `http://ddev-commerce24-web/api`, not `*.ddev.site`. |
| "Commerce24: Missing or invalid API key. (HTTP 401)" | Wrong or revoked key. Issue a new one and update `.env`. |
| "Commerce24: That license key is not on your account. (HTTP 422)" | The licence key belongs to another customer than the API key. |
| "Commerce24: Your plan allows N website(s) (HTTP 422)" | The licence is already active on the plan's maximum number of sites. Deactivate one, or choose a bigger plan. |
| A download fails with "signature … untrusted" / "unsigned" | `COMMERCE24_SIGNING_*` doesn't match Commerce24's key. Copy it again (§2.4). |
| A paid package shows "Not in your plan" / 403 | Expected: give the customer a plan that includes it, or a purchase, in the Commerce24 admin. |
| Saving Settings fails: "Unable to write new project config files … Permission denied" | The server's `config/project` isn't writable, and its `allowAdminChanges` is on. Turn it off there (`CRAFT_ALLOW_ADMIN_CHANGES=false`); the Settings screen then turns read-only. Set the connection in `.env` (§1). |
| Account & License shows old data | Commerce24 answers are cached for 5 minutes (Settings → Commerce cache duration). Any change made from the plugin clears the cache straight away. The Updates list and a kit's Check always read the catalog fresh. |
| A "Manage subscription" link opens an address that doesn't exist | Commerce24's `APP_URL` must be its public address (fixed in the local app). |
