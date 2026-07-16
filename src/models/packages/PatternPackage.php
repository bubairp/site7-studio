<?php

namespace site7\studio\models\packages;

/**
 * Represents a PatternPackage.
 */
class PatternPackage extends Package
{
    /**
     * @inheritdoc
     */
    public static function getPackageType(): string
    {
        return 'pattern';
    }
}
