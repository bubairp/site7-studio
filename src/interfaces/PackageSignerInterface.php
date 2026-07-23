<?php

namespace site7\studio\interfaces;

/**
 * Extension point only - per Phase 14's explicit scope ("Prepare architecture
 * for Package Signing / Signature Validation / Publisher Verification. Do
 * not implement cryptography yet."). No implementation of this interface
 * does any real signing; NullPackageSigner is the only one that exists, and
 * it's a deliberate no-op. A future milestone implementing real signing
 * (e.g. openssl-based) only needs to implement this interface and register
 * it in place of NullPackageSigner - PackagePublisherService already calls
 * $this->signer->sign() unconditionally, so nothing else changes.
 */
interface PackageSignerInterface
{
    /** Whether this signer can actually produce/verify signatures (NullPackageSigner always returns false). */
    public function isEnabled(): bool;

    /**
     * Signs a built .s7pkg and returns a signature string to persist
     * alongside the publication (or null if signing is disabled/unavailable).
     */
    public function sign(string $s7pkgPath): ?string;

    /**
     * Verifies a package's signature. NullPackageSigner always returns true
     * (nothing to verify when nothing was ever signed) rather than false, so
     * that turning signing on later doesn't retroactively fail every
     * already-published, unsigned package.
     */
    public function verify(string $s7pkgPath, ?string $signature): bool;
}
