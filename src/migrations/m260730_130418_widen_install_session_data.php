<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260730_130418_widen_install_session_data migration.
 *
 * Several tables store a Starter Kit's full blueprint.json (or a report
 * built from one) as a JSON blob in a `text` column, which tops out at
 * 65,535 bytes. A blueprint for a real, multi-page site easily exceeds that
 * - a 4-page test capture alone produced a ~340KB blueprint - so the
 * install/sync flows that write these columns fail outright with
 * `SQLSTATE[22001]: String data, right truncated` the moment a real Starter
 * Kit (not a handful of synthetic test pages) is installed or synced.
 * `mediumtext` (16MB) comfortably covers any realistic blueprint:
 *  - site7_install_sessions.data (InstallationSessionService::create())
 *  - site7_installed_starter_kits.blueprintSnapshot (the Sync baseline)
 *  - site7_sync_history.report
 *  - site7_sync_sessions.data
 */
class m260730_130418_widen_install_session_data extends Migration
{
    public function safeUp(): bool
    {
        // Guarded like every other migration in this codebase: these tables
        // are only guaranteed to exist when this runs as part of a real
        // incremental upgrade. When Install.php replays the full migration
        // chain (fresh install) or reverses it (uninstall), the guard makes
        // this migration order-tolerant instead of throwing "table doesn't
        // exist" if it's ever invoked before/after its target tables are
        // present.
        if ($this->db->tableExists('{{%site7_install_sessions}}')) {
            $this->alterColumn('{{%site7_install_sessions}}', 'data', $this->mediumText()->notNull());
        }
        if ($this->db->tableExists('{{%site7_installed_starter_kits}}')) {
            $this->alterColumn('{{%site7_installed_starter_kits}}', 'blueprintSnapshot', $this->mediumText()->notNull());
        }
        if ($this->db->tableExists('{{%site7_sync_history}}')) {
            $this->alterColumn('{{%site7_sync_history}}', 'report', $this->mediumText()->notNull());
        }
        if ($this->db->tableExists('{{%site7_sync_sessions}}')) {
            $this->alterColumn('{{%site7_sync_sessions}}', 'data', $this->mediumText()->notNull());
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists('{{%site7_install_sessions}}')) {
            $this->alterColumn('{{%site7_install_sessions}}', 'data', $this->text()->notNull());
        }
        if ($this->db->tableExists('{{%site7_installed_starter_kits}}')) {
            $this->alterColumn('{{%site7_installed_starter_kits}}', 'blueprintSnapshot', $this->text()->notNull());
        }
        if ($this->db->tableExists('{{%site7_sync_history}}')) {
            $this->alterColumn('{{%site7_sync_history}}', 'report', $this->text()->notNull());
        }
        if ($this->db->tableExists('{{%site7_sync_sessions}}')) {
            $this->alterColumn('{{%site7_sync_sessions}}', 'data', $this->text()->notNull());
        }

        return true;
    }
}
