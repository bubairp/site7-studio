<?php

namespace site7\studio\services\publishing;

use site7\studio\interfaces\PackageSignerInterface;

/**
 * The only PackageSignerInterface implementation today - see that
 * interface's docblock. Deliberately does nothing.
 */
class NullPackageSigner implements PackageSignerInterface
{
    /**
     * @inheritdoc
     */
    public function isEnabled(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function sign(string $s7pkgPath): ?string
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function verify(string $s7pkgPath, ?string $signature): bool
    {
        return true;
    }
}
