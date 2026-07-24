<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;
use craft\db\ActiveQuery;

/**
 * SharedResourceRecord
 */
class SharedResourceRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%site7_shared_resources}}';
    }

    /**
     * Shared -> Shared forward edges owned by this Shared Resource.
     */
    public function getDependencies(): ActiveQuery
    {
        return $this->hasMany(SharedResourceDependencyRecord::class, ['sharedResourceId' => 'id']);
    }
}
