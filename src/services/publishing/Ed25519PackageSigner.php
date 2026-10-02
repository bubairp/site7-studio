<?php

namespace site7\studio\services\publishing;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use site7\studio\interfaces\PackageSignerInterface;
use site7\studio\models\marketplace\SignatureVerification;

/**
 * Ed25519 package signing (libsodium). Format: docs/47_PACKAGE_SIGNING.md.
 *
 * Commerce24 holds the private key and signs each published version before
 * serving it; sites only verify. The signature covers the exact bytes of
 * the archive's bundle-manifest.json, which lists every bundled package's
 * directory checksum - so one signature covers every file, the manifest's
 * pricingType included. It's stored in the archive as signature.json, at
 * the root, outside every packages/<handle>/ directory, so it never affects
 * those checksums.
 *
 * Trusted public keys: BUILT_IN_KEYS plus `trustedSigningKeys` from
 * config/site7-studio.php (a config file, never project config or the CP, so
 * a key can't be added from the browser). The private key is only ever read
 * from the environment, on the signing host.
 */
class Ed25519PackageSigner extends Component implements PackageSignerInterface
{
    public const SIGNATURE_FILE = 'signature.json';
    public const SIGNED_FILE = 'bundle-manifest.json';
    public const ALGORITHM = 'ed25519';

    /** Env vars read on the signing host only. */
    public const ENV_SECRET_KEY = 'SITE7_SIGNING_SECRET_KEY';
    public const ENV_KEY_ID = 'SITE7_SIGNING_KEY_ID';

    /**
     * Public keys every install trusts, keyed by key ID (base64). Add
     * Commerce24's production key here once it's generated - until then
     * only keys from config/site7-studio.php are trusted.
     */
    public const BUILT_IN_KEYS = [];

    /**
     * Overrides the trusted keys (keyId => base64 public key); null reads
     * BUILT_IN_KEYS plus config/site7-studio.php. Set by tests.
     */
    public ?array $trustedKeys = null;

    /** Overrides the signing key pair; null reads the environment. Set by tests. */
    public ?string $secretKey = null;
    public ?string $keyId = null;

    /**
     * @inheritdoc
     */
    public function isEnabled(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * Signs the archive in place by adding signature.json. Only works where
     * a secret key is configured (the signing host); returns null elsewhere.
     *
     * @inheritdoc
     * @throws \Exception if the archive can't be read or written.
     */
    public function sign(string $s7pkgPath): ?string
    {
        $secretKey = $this->secretKey ?? (string)App::env(self::ENV_SECRET_KEY);
        $keyId = $this->keyId ?? (string)App::env(self::ENV_KEY_ID);
        if (!$this->isEnabled() || $secretKey === '' || $keyId === '') {
            return null;
        }

        $secret = base64_decode($secretKey, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \Exception(self::ENV_SECRET_KEY . ' is not a base64 Ed25519 secret key.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($s7pkgPath) !== true) {
            throw new \Exception("Could not open archive: {$s7pkgPath}");
        }
        $payload = $zip->getFromName(self::SIGNED_FILE);
        if ($payload === false) {
            $zip->close();
            throw new \Exception("Archive has no " . self::SIGNED_FILE . " to sign.");
        }

        $signature = base64_encode(sodium_crypto_sign_detached($payload, $secret));
        $zip->addFromString(self::SIGNATURE_FILE, json_encode([
            'algorithm' => self::ALGORITHM,
            'keyId' => $keyId,
            'signature' => $signature,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $signature;
    }

    /**
     * True only for a signature that verifies against a trusted key. Pass
     * null to read signature.json from the archive.
     *
     * @inheritdoc
     */
    public function verify(string $s7pkgPath, ?string $signature): bool
    {
        return $this->verifyArchive($s7pkgPath, $signature)->isVerified();
    }

    /**
     * @param string|null $signatureJson signature.json's contents; null reads it from the archive.
     */
    public function verifyArchive(string $s7pkgPath, ?string $signatureJson = null): SignatureVerification
    {
        $zip = new \ZipArchive();
        if ($zip->open($s7pkgPath) !== true) {
            return $this->invalid(null, 'The archive could not be opened.');
        }
        $payload = $zip->getFromName(self::SIGNED_FILE);
        $signatureJson ??= $zip->getFromName(self::SIGNATURE_FILE);
        $zip->close();

        if ($signatureJson === false || $signatureJson === null) {
            return new SignatureVerification([
                'status' => SignatureVerification::UNSIGNED,
                'message' => 'This archive is not signed.',
            ]);
        }

        $data = json_decode($signatureJson, true);
        $keyId = is_array($data) && is_string($data['keyId'] ?? null) ? $data['keyId'] : null;
        if (!is_array($data) || ($data['algorithm'] ?? null) !== self::ALGORITHM || $keyId === null || !is_string($data['signature'] ?? null)) {
            return $this->invalid($keyId, self::SIGNATURE_FILE . ' is malformed.');
        }
        if ($payload === false) {
            return $this->invalid($keyId, 'The archive has no ' . self::SIGNED_FILE . '.');
        }
        if (!$this->isEnabled()) {
            return $this->invalid($keyId, 'PHP\'s sodium extension is missing, so the signature cannot be checked.');
        }

        $publicKey = base64_decode((string)($this->getTrustedKeys()[$keyId] ?? ''), true);
        if (!$publicKey || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $this->invalid($keyId, "Signed with key '{$keyId}', which this site does not trust.");
        }

        $signature = base64_decode($data['signature'], true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
            return $this->invalid($keyId, 'The signature does not match - the archive was altered after it was signed.');
        }

        return new SignatureVerification([
            'status' => SignatureVerification::VERIFIED,
            'keyId' => $keyId,
            'message' => "Signed by trusted key '{$keyId}'.",
        ]);
    }

    /**
     * @return array<string, string> keyId => base64 public key
     */
    public function getTrustedKeys(): array
    {
        if ($this->trustedKeys !== null) {
            return $this->trustedKeys;
        }

        $fromConfig = Craft::$app->getConfig()->getConfigFromFile('site7-studio')['trustedSigningKeys'] ?? [];
        return array_merge(self::BUILT_IN_KEYS, is_array($fromConfig) ? $fromConfig : []);
    }

    /**
     * A new key pair, all base64. Run once, on the signing host.
     *
     * @return array{publicKey: string, secretKey: string}
     */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();
        return [
            'publicKey' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secretKey' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    private function invalid(?string $keyId, string $message): SignatureVerification
    {
        return new SignatureVerification([
            'status' => SignatureVerification::INVALID,
            'keyId' => $keyId,
            'message' => $message,
        ]);
    }
}
