# 47 — Package Signing

## 1. Purpose

Lets a site tell a package Commerce24 actually published apart from one that was altered afterwards — in transit, or by someone relabelling a paid package as free before passing the `.s7pkg` on.

## 2. What It Does

Commerce24 signs every published version with an Ed25519 private key only it holds. Sites ship with the matching public key(s) and verify on import. The signature covers `bundle-manifest.json`, which lists a checksum for every bundled package directory, so one signature vouches for every file — `pricingType` in each package's `manifest.json` included.

## 3. Current Status

**Implemented** (2026-10-02). `Ed25519PackageSigner` is the registered `packageSigner`; `NullPackageSigner` remains in the codebase, unregistered. **No production key is built in yet** — `Ed25519PackageSigner::BUILT_IN_KEYS` is empty until Commerce24 generates its key (§9).

## 4. Archive format

A signed `.s7pkg` is the usual zip with one extra root entry, `signature.json`:

```json
{
  "algorithm": "ed25519",
  "keyId": "c24-2026",
  "signature": "<base64 of the 64-byte detached signature>"
}
```

- **Signed bytes:** the exact bytes of the archive's `bundle-manifest.json` entry, unmodified (no re-encoding, no trimming).
- **Algorithm:** Ed25519 detached signature — PHP `sodium_crypto_sign_detached($bundleManifestBytes, $secretKey)`.
- **`keyId`:** free-form string naming the key pair, so keys can be rotated: sites trust a set of `keyId => publicKey`.
- `signature.json` sits at the archive root, outside every `packages/<handle>/` directory, so it never changes any package checksum. Signing an archive twice just replaces it.
- Every entry in `bundle-manifest.json`'s `packages` must carry a `checksum` (the plugin's exporter always writes one); a signed archive missing one is refused, since checksums are how the signature covers files.

## 5. Verification and import rules

`PackageImportService::validatePackage($path, $requireSignature)` calls `Ed25519PackageSigner::verifyArchive()` after the per-package checksum checks:

| Archive | Result |
|---|---|
| Signature valid, from a trusted key | Imports. Each package record stores `signatureStatus = verified`, `signatureKeyId`, and `verifiedPricingType` (the signed `pricingType`). |
| `signature.json` present but malformed, unknown `keyId`, or not matching | **Refused**, always. |
| Unsigned, downloaded from Commerce24 (`MarketplaceService::installFromRepository()`) | **Refused** — Commerce24 signs everything it serves. |
| Unsigned, contains any package whose `pricingType` isn't `free` | **Refused**. |
| Unsigned, only free packages (e.g. an export between your own sites) | Imports with a warning; records `signatureStatus = unsigned`. |

The licence gate (`commerce/PackageService::isPaidPackage()`) prefers `verifiedPricingType` over `manifest.json` on disk, so editing an imported manifest to `free` doesn't make a paid package free.

## 6. Trusted keys

`Ed25519PackageSigner::getTrustedKeys()` = `BUILT_IN_KEYS` + `trustedSigningKeys` from `config/site7-studio.php`. Keys come from code or a config file only — never project config or the CP — so they can't be added from the browser. This project's config file reads one extra key from `COMMERCE24_SIGNING_KEY_ID` / `COMMERCE24_SIGNING_PUBLIC_KEY` in `.env`; locally that's the mock's key.

## 7. Console

- `php craft site7-studio/signing/keygen` — prints a new key pair (base64).
- `php craft site7-studio/signing/sign <file.s7pkg>` — signs in place with `SITE7_SIGNING_SECRET_KEY` / `SITE7_SIGNING_KEY_ID` from the environment. Reference for Commerce24's implementation; exits non-zero when no key is set.
- `php craft site7-studio/signing/verify <file.s7pkg>` — checks against this site's trusted keys.

`PackagePublisherService` still calls `sign()` after publishing; on an author site no key is set, so it's a no-op.

## 8. Commerce24 implementation (Laravel)

1. Generate one key pair (`signing/keygen`, or `sodium_crypto_sign_keypair()`); keep the secret key in Commerce24's `.env`, never in git. Send the public key + chosen `keyId` to the plugin maintainers for `BUILT_IN_KEYS`.
2. When serving `GET /marketplace/download/{handle}`, add `signature.json` per §4 to the archive before base64-encoding it into `contentsBase64`. Signing once at publish time and storing the signed archive is equivalent.
3. Rotation: sign with a new `keyId`; ship its public key in a plugin release before switching, and keep the old key trusted until no site needs it.

The mock (`mock-commerce24/router.php`, local only) does exactly this with its own key in `mock-commerce24/signing-key.json`; `GET /signing/public-key` returns it, and `?unsigned=1` on the download serves the archive unsigned for testing.

## 9. Rollout order

1. Commerce24 generates its key and starts signing downloads (§8).
2. Its public key goes into `BUILT_IN_KEYS` in a plugin release.
3. Sites update the plugin. Until step 2 ships, Commerce24 downloads on those sites fail as untrusted — sign first, then release.

## 10. Limits

Signing proves a package is exactly what Commerce24 published. It does not stop the owner of a site from editing the plugin's PHP to skip the check — no client-side licensing can. Nor can it tell an unsigned package that was relabelled free *and renamed* from a genuinely free local one; that's why paid packages must arrive signed. The real enforcement remains Commerce24 only delivering paid packages to entitled sites.

## 11. Tests

- `tests/unit/services/publishing/Ed25519PackageSignerTest.php` — round trip, unsigned, altered manifest, untrusted key, unknown key ID, malformed `signature.json`, signing without a key, paid pricing types.
- Live-verified 2026-10-02 against the mock: signed download imports and records the signature; a post-import `manifest.json` edit is ignored by the licence gate; unsigned Commerce24 download, unsigned paid upload, relabelled archive with recomputed checksums, and an attacker-key signature are all refused; an unsigned free archive imports with a warning.
