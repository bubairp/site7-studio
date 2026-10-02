<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * Install migration.
 *
 * craft\base\Plugin::install() only runs this migration on a fresh install,
 * then marks every numbered migration as applied without running it. So
 * this migration must produce the complete current schema - it does that by
 * replaying each numbered migration's safeUp() (and safeDown() in reverse),
 * rather than duplicating their DDL here. Every numbered migration guards
 * itself with tableExists()/columnExists(), so replaying is idempotent.
 *
 * Order is NOT plain filename order: m260730_130418_widen_install_session_data
 * alters tables created by m260730_140000_create_synchronization_tables, so
 * the latter is listed first. Files are not renamed, to keep recorded
 * migration history stable for upgrading installs.
 *
 * Every new numbered migration must be added to migrations() -
 * tests/unit/migrations/InstallTest.php fails otherwise (S7V-001).
 */
class Install extends Migration
{
    /**
     * Every numbered migration, in the order it must run.
     *
     * @return string[] migration class names
     */
    public static function migrations(): array
    {
        return [
            m260716_100535_create_package_tables::class,
            m260722_155849_add_authoring_status::class,
            m260722_190000_add_package_creator::class,
            m260723_110923_create_package_publications_table::class,
            m260724_130000_create_shared_resources_tables::class,
            m260728_120000_add_entitlement_removable_on::class,
            m260730_123644_add_package_category_tags::class,
            m260730_130000_create_install_sessions_table::class,
            m260730_140000_create_synchronization_tables::class,
            m260730_130418_widen_install_session_data::class,
            m260731_150000_create_section_import_sources_table::class,
            m260801_000000_create_page_import_sources_table::class,
            m260802_000000_create_website_import_sources_table::class,
            m260813_142335_add_package_version_archive_path::class,
            m260817_150000_create_installed_files_table::class,
            m261002_000000_store_matrix_field_uid::class,
        ];
    }

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        foreach (static::migrations() as $class) {
            if ((new $class(['db' => $this->db]))->safeUp() === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        foreach (array_reverse(static::migrations()) as $class) {
            if ((new $class(['db' => $this->db]))->safeDown() === false) {
                return false;
            }
        }

        return true;
    }
}
