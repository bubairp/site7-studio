<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260730_140000_create_synchronization_tables migration.
 *
 * Adds the Synchronization & Update Engine's own tables (Website Starter Kit
 * System Phase 8):
 *
 * - site7_installed_starter_kits: one row per Starter Kit ever successfully
 *   installed on this Craft installation, the baseline SynchronizationPlanner
 *   diffs a newer Blueprint against - {handle, installedVersion,
 *   blueprintSnapshot (json), installedAt, lastSyncedAt}.
 * - site7_sync_history: one row per synchronization run, successful or not -
 *   {handle, fromVersion, toVersion, status, report (json)}.
 * - site7_sync_sessions: one row per in-progress/completed SynchronizationSession,
 *   the Update Wizard's counterpart to site7_install_sessions.
 */
class m260730_140000_create_synchronization_tables extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%site7_installed_starter_kits}}')) {
            $this->createTable('{{%site7_installed_starter_kits}}', [
                'id' => $this->primaryKey(),
                'handle' => $this->string()->notNull(),
                'installedVersion' => $this->string()->notNull(),
                'blueprintSnapshot' => $this->text()->notNull(),
                'installedAt' => $this->dateTime()->notNull(),
                'lastSyncedAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%site7_installed_starter_kits}}', 'handle', true);
        }

        if (!$this->db->tableExists('{{%site7_sync_history}}')) {
            $this->createTable('{{%site7_sync_history}}', [
                'id' => $this->primaryKey(),
                'handle' => $this->string()->notNull(),
                'fromVersion' => $this->string()->notNull(),
                'toVersion' => $this->string()->notNull(),
                'status' => $this->string()->notNull(),
                'report' => $this->text()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%site7_sync_history}}', 'handle', false);
        }

        if (!$this->db->tableExists('{{%site7_sync_sessions}}')) {
            $this->createTable('{{%site7_sync_sessions}}', [
                'id' => $this->primaryKey(),
                'uid' => $this->uid()->notNull(),
                'handle' => $this->string()->notNull(),
                'status' => $this->string()->notNull()->defaultValue('created'),
                'data' => $this->text()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%site7_sync_sessions}}', 'uid', true);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%site7_sync_sessions}}');
        $this->dropTableIfExists('{{%site7_sync_history}}');
        $this->dropTableIfExists('{{%site7_installed_starter_kits}}');

        return true;
    }
}
