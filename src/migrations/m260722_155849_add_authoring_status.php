<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260722_155849_add_authoring_status migration.
 *
 * Adds the Package Authoring Platform's lifecycle status (draft/preview/
 * testing/published/deprecated/archived) as its own column, separate from
 * the existing `status` column - which already means something different
 * (available/installed/enabled/disabled, the install-lifecycle the Package
 * Engine and Library have used since Phase 7). Overloading that column
 * would silently break every existing status check; this is purely
 * additive.
 */
class m260722_155849_add_authoring_status extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%site7_packages}}', 'authoringStatus')) {
            $this->addColumn(
                '{{%site7_packages}}',
                'authoringStatus',
                $this->string()->notNull()->defaultValue('published')->after('status')
            );

            // Packages discovered before this migration existed are real,
            // working packages already - default them to "published" (only
            // packages created through the new wizard start as "draft").
            $this->update('{{%site7_packages}}', ['authoringStatus' => 'published'], [], [], false);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%site7_packages}}', 'authoringStatus')) {
            $this->dropColumn('{{%site7_packages}}', 'authoringStatus');
        }

        return true;
    }
}
