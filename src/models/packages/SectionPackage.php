<?php

namespace site7\studio\models\packages;

/**
 * Represents a SectionPackage.
 */
class SectionPackage extends Package
{
    /**
     * @inheritdoc
     */
    public static function getPackageType(): string
    {
        return 'section';
    }
}
