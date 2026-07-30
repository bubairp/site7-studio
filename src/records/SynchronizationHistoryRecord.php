<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * One row per synchronization run, successful or not (Website Starter Kit
 * System Phase 8) - the update history the Update Wizard's Step 5 and any
 * future Marketplace/Cloud update UI reads from.
 */
class SynchronizationHistoryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%site7_sync_history}}';
    }
}
