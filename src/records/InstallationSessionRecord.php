<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * Persists InstallationSession (Website Starter Kit System Phase 7) as a
 * single JSON blob per row, keyed by its own uid rather than an
 * auto-increment id - sessions are looked up by the uid handed to the
 * browser/queue job/console command, never by a sequential id. A thin
 * storage record only: InstallationSessionService owns all
 * serialize/deserialize logic, this class owns none.
 */
class InstallationSessionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%site7_install_sessions}}';
    }
}
