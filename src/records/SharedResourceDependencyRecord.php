<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * SharedResourceDependencyRecord - a Shared Resource -> Shared Resource
 * forward edge (e.g. blockStyle depends on button). Package -> Shared
 * Resource edges are recorded separately, in the existing
 * PackageDependencyRecord/site7_package_dependencies table (dependencyType
 * = 'sharedResource') - see MarketplaceService::syncDependencyRecords().
 */
class SharedResourceDependencyRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%site7_shared_resource_dependencies}}';
    }
}
