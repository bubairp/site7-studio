<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * Persists SynchronizationSession (Website Starter Kit System Phase 8) as a
 * single JSON blob per row, keyed by its own uid - mirrors
 * InstallationSessionRecord's shape exactly, kept as a separate table since
 * a sync session's fields (plan, confirmed removals, before/after versions)
 * are a distinct concern from an install session's.
 */
class SynchronizationSessionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%site7_sync_sessions}}';
    }
}
