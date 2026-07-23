<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQuery;

/**
 * PackagePublicationRecord - one row per publish attempt (see the
 * site7_package_publications migration's docblock for why this is a
 * separate table rather than another column on site7_packages/
 * site7_package_versions).
 */
class PackagePublicationRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%site7_package_publications}}';
    }

    /**
     * The package this publication is for - null if it's since been deleted
     * (the FK is ON DELETE CASCADE, so in practice a dangling row can't
     * exist, but the aggregate history view still handles a missing package
     * gracefully rather than assuming this always resolves).
     */
    public function getPackage(): ActiveQuery
    {
        return $this->hasOne(PackageRecord::class, ['id' => 'packageId']);
    }
}
