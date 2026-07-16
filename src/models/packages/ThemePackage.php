<?php

namespace site7\studio\models\packages;

/**
 * Represents a ThemePackage.
 */
class ThemePackage extends Package
{
    /**
     * @inheritdoc
     */
    public static function getPackageType(): string
    {
        return 'theme';
    }
}
