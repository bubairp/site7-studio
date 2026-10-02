<?php

namespace site7\studio\models\marketplace;

use craft\base\Model;

/**
 * The outcome of checking a .s7pkg's signature.json - see
 * Ed25519PackageSigner::verifyArchive() and docs/47_PACKAGE_SIGNING.md.
 */
class SignatureVerification extends Model
{
    /** Signed by a trusted key and the signature matches. */
    public const VERIFIED = 'verified';
    /** No signature.json in the archive. */
    public const UNSIGNED = 'unsigned';
    /** A signature is present but malformed, from an unknown key, or doesn't match. */
    public const INVALID = 'invalid';

    public string $status = self::UNSIGNED;

    /** The key ID signature.json names, when there is one. */
    public ?string $keyId = null;

    /** Why the status isn't VERIFIED, for showing to the admin. */
    public string $message = '';

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }
}
