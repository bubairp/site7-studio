<?php

namespace site7\studio\models\packages;

/**
 * A design package (docs/60): a site's look - template overrides and the
 * built CSS/JS - on top of the Theme, never structure.
 */
class DesignPackage extends Package
{
    /**
     * @inheritdoc
     */
    public static function getPackageType(): string
    {
        return 'design';
    }
}
