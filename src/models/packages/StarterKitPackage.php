<?php

namespace site7\studio\models\packages;

/**
 * Represents a StarterKitPackage.
 */
class StarterKitPackage extends Package
{
    /**
     * @inheritdoc
     */
    public static function getPackageType(): string
    {
        return 'starter-kit';
    }
}
