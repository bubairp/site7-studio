<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * One row per Starter Kit ever successfully installed on this Craft
 * installation (Website Starter Kit System Phase 8) - the baseline
 * SynchronizationPlanner compares a newer Blueprint against. Distinct from
 * PackageRecord (site7_packages), which tracks packages *authored* on this
 * site, not packages installed *onto* it from elsewhere.
 */
class InstalledStarterKitRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%site7_installed_starter_kits}}';
    }
}
