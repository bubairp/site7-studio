<?php

namespace site7\studio\models\packages;

/**
 * Represents a TemplatePackage.
 */
class TemplatePackage extends Package
{
    /**
     * @inheritdoc
     */
    public static function getPackageType(): string
    {
        return 'template';
    }
}
