<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260730_130000_create_install_sessions_table migration.
 *
 * Adds site7_install_sessions (Website Starter Kit System Phase 7 - the
 * Fresh-Install Setup Wizard): one row per InstallationSession, storing its
 * entire state as a JSON blob keyed by uid. A real install can span several
 * process boundaries (a fresh OS process per InstallationOrchestratorService
 * stage - see PHASE-7-FRESH-INSTALL-SETUP-WIZARD.md), so this state has to
 * survive outside PHP memory between the CP wizard's polling requests, the
 * queue job, and any console-invoked stage.
 */
class m260730_130000_create_install_sessions_table extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%site7_install_sessions}}')) {
            $this->createTable('{{%site7_install_sessions}}', [
                'id' => $this->primaryKey(),
                'uid' => $this->uid()->notNull(),
                'starterKitHandle' => $this->string()->notNull(),
                'status' => $this->string()->notNull()->defaultValue('created'),
                'data' => $this->text()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%site7_install_sessions}}', 'uid', true);
            $this->createIndex(null, '{{%site7_install_sessions}}', 'status', false);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%site7_install_sessions}}');

        return true;
    }
}
