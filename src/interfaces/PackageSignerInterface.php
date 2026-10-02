<?php

namespace site7\studio\interfaces;

/**
 * Package signing. Ed25519PackageSigner is the registered implementation
 * (see docs/47_PACKAGE_SIGNING.md); NullPackageSigner is the old no-op,
 * kept unregistered.
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
