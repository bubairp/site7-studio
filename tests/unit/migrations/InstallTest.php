<?php

namespace site7\studio\tests\unit\migrations;

use PHPUnit\Framework\TestCase;
use craft\db\Migration;
use site7\studio\migrations\Install;

/**
 * Regression coverage for S7V-001: a fresh plugin install recorded all 16
 * migrations as "applied" (craft\base\Plugin::install() marks every
 * pending migration as applied without running it, once the Install
 * migration itself has run) while creating zero site7_* tables, because
 * Install::safeUp() was a no-op stub instead of describing the full schema.
 *
 * The fix makes Install::safeUp()/safeDown() replay every numbered
 * migration's safeUp()/safeDown() via the list in Install::migrations().
 * This suite has no live Craft app/DB (see tests/unit.suite.yml), so it
 * can't execute those migrations against a real database - that's
 * verified by hand against a real Craft install for this change (see
 * VALIDATION_REPORT.md in the site7-validation project). What it *can*
 * verify, without a DB, is that the replay list itself can't silently
 * drift from the actual migration files on disk - which is exactly the
 * failure mode (a migration existing but never being replayed) that
 * caused this bug in the first place.
 */
class InstallTest extends TestCase
{
    /**
     * Every m*.php migration file actually present in src/migrations,
     * derived from the filesystem rather than hardcoded, so this test
     * fails the moment a new migration file is added without also being
     * added to Install::migrations().
     *
     * @return string[] class names, unsorted
     */
    private function migrationFilesOnDisk(): array
    {
        $dir = dirname((new \ReflectionClass(Install::class))->getFileName());
        $classes = [];

        foreach (glob($dir . '/m*.php') as $file) {
            $classes[] = 'site7\\studio\\migrations\\' . basename($file, '.php');
        }

        return $classes;
    }

    public function testMigrationsListIsNotEmpty(): void
    {
        $this->assertNotEmpty(Install::migrations());
    }

    public function testMigrationsListHasNoDuplicates(): void
    {
        $migrations = Install::migrations();

        $this->assertCount(
            count($migrations),
            array_unique($migrations),
            'Install::migrations() lists the same migration more than once.'
        );
    }

    public function testMigrationsListMatchesEveryMigrationFileOnDisk(): void
    {
        $onDisk = $this->migrationFilesOnDisk();
        $listed = Install::migrations();

        sort($onDisk);
        sort($listed);

        $this->assertSame(
            $onDisk,
            $listed,
            "Install::migrations() is out of sync with src/migrations/m*.php.\n" .
            "This is exactly how S7V-001 happened: a migration file exists but a fresh " .
            "install never replays its safeUp(), so its tables/columns are never created " .
            "even though craft\\base\\Plugin::install() marks it as applied. Add every new " .
            "numbered migration to Install::migrations(), in the order it must actually run."
        );
    }

    public function testEveryListedMigrationClassExistsAndExtendsCraftMigration(): void
    {
        foreach (Install::migrations() as $class) {
            $this->assertTrue(class_exists($class), "Migration class $class does not exist.");
            $this->assertTrue(
                is_subclass_of($class, Migration::class),
                "$class must extend craft\\db\\Migration."
            );
        }
    }

    /**
     * Regression guard for the ordering bug found while fixing S7V-001:
     * m260730_130418_widen_install_session_data alters columns on tables
     * (site7_installed_starter_kits, site7_sync_history, site7_sync_sessions)
     * that m260730_140000_create_synchronization_tables creates - despite
     * widen's filename timestamp (13:04) sorting before
     * create_synchronization_tables' (14:00). Replaying strictly in
     * filename order throws "table doesn't exist" on a fresh install/
     * uninstall. This asserts the corrected relative order stays correct.
     */
    public function testSynchronizationTablesAreCreatedBeforeWidenAltersThem(): void
    {
        $migrations = Install::migrations();

        $createIndex = array_search(
            'site7\\studio\\migrations\\m260730_140000_create_synchronization_tables',
            $migrations,
            true
        );
        $widenIndex = array_search(
            'site7\\studio\\migrations\\m260730_130418_widen_install_session_data',
            $migrations,
            true
        );

        $this->assertNotFalse($createIndex, 'create_synchronization_tables is missing from the list.');
        $this->assertNotFalse($widenIndex, 'widen_install_session_data is missing from the list.');
        $this->assertLessThan(
            $widenIndex,
            $createIndex,
            'create_synchronization_tables must run before widen_install_session_data, ' .
            'or safeUp()/safeDown() will alter columns on tables that do not exist yet.'
        );
    }

    /**
     * Same class of bug, different table: create_install_sessions_table
     * must also precede widen (which alters site7_install_sessions).
     */
    public function testInstallSessionsTableIsCreatedBeforeWidenAltersIt(): void
    {
        $migrations = Install::migrations();

        $createIndex = array_search(
            'site7\\studio\\migrations\\m260730_130000_create_install_sessions_table',
            $migrations,
            true
        );
        $widenIndex = array_search(
            'site7\\studio\\migrations\\m260730_130418_widen_install_session_data',
            $migrations,
            true
        );

        $this->assertNotFalse($createIndex);
        $this->assertNotFalse($widenIndex);
        $this->assertLessThan($widenIndex, $createIndex);
    }
}
