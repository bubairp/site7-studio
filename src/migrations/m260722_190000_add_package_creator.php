<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260722_190000_add_package_creator migration.
 *
 * Adds creatorId to rp_site7_packages, so a Template captured through the
 * "Save as Template" flow (open to every user, unlike the rest of Package
 * Authoring which is gated to the new Package Authoring permission) can be
 * deleted by the user who created it, without granting them that broader
 * permission.
 */
class m260722_190000_add_package_creator extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%site7_packages}}', 'creatorId')) {
            $this->addColumn(
                '{{%site7_packages}}',
                'creatorId',
                $this->integer()->after('authoringStatus')
            );

            $this->createIndex(null, '{{%site7_packages}}', 'creatorId', false);
            $this->addForeignKey(
                null,
                '{{%site7_packages}}',
                'creatorId',
                '{{%users}}',
                'id',
                'SET NULL',
                null
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%site7_packages}}', 'creatorId')) {
            $this->dropColumn('{{%site7_packages}}', 'creatorId');
        }

        return true;
    }
}
