<?php

namespace site7\studio\tests\unit\services\library;

use Codeception\Test\Unit;
use site7\studio\migrations\Install;
use site7\studio\services\library\TrackingSnapshot;

class TrackingSnapshotTest extends Unit
{
    protected \UnitTester $tester;

    public function testCommerceSettingsNeverGoIntoTheSnapshot(): void
    {
        $kept = TrackingSnapshot::filterSettings([
            'matrixFieldUid' => 'abc',
            'libraryPath' => '@root/library',
            'packageCategories' => ['Header'],
            'commerceApiKey' => 'secret',
            'commerceApiEndpoint' => 'https://c24.test',
            'commerceStoreIdentifier' => 'store',
            'commerceOfflineFeatures' => ['x'],
            'defaultPackage' => 'pro',
        ]);

        $this->assertSame(['matrixFieldUid', 'libraryPath', 'packageCategories'], array_keys($kept));
    }

    public function testLicenceColumnsAndSessionsAreExcluded(): void
    {
        $this->assertContains('entitlementRemovableOn', TrackingSnapshot::EXCLUDED_PACKAGE_COLUMNS);
        $this->assertContains('verifiedPricingType', TrackingSnapshot::EXCLUDED_PACKAGE_COLUMNS);
        foreach (TrackingSnapshot::EXCLUDED_TABLES as $table) {
            $this->assertArrayNotHasKey($table, TrackingSnapshot::TABLES);
        }
    }

    public function testParentsAreRestoredBeforeTheRowsThatPointAtThem(): void
    {
        $order = array_keys(TrackingSnapshot::TABLES);
        foreach (TrackingSnapshot::TABLES as $table => $definition) {
            foreach ($definition['fks'] as $parent) {
                $this->assertLessThan(array_search($table, $order, true), array_search($parent, $order, true), "{$parent} before {$table}");
            }
        }
    }

    /** A new tracking table must be snapshotted or explicitly excluded. */
    public function testEveryTableTheInstallMigrationCreatesIsCovered(): void
    {
        $created = [];
        foreach (Install::migrations() as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            preg_match_all("/createTable\\('\\{\\{%(site7_[a-z_]+)\\}\\}'/", (string)file_get_contents($file), $matches);
            $created = array_merge($created, $matches[1]);
        }

        $this->assertNotEmpty($created);
        foreach (array_unique($created) as $table) {
            $this->assertTrue(isset(TrackingSnapshot::TABLES[$table]) || in_array($table, TrackingSnapshot::EXCLUDED_TABLES, true), "{$table} is neither snapshotted nor excluded");
        }
    }

    public function testASectionWhoseBlockIsGoneIsAvailableAgain(): void
    {
        $this->assertSame('available', TrackingSnapshot::checkedSectionStatus('enabled', 1, 0, true));
        $this->assertSame('available', TrackingSnapshot::checkedSectionStatus('installed', 2, 0, null));
    }

    public function testAnEnabledSectionOffThePageBuilderIsInstalled(): void
    {
        $this->assertSame('installed', TrackingSnapshot::checkedSectionStatus('enabled', 1, 1, false));
        $this->assertSame('enabled', TrackingSnapshot::checkedSectionStatus('enabled', 1, 1, true));
        $this->assertSame('enabled', TrackingSnapshot::checkedSectionStatus('enabled', 1, 1, null));
        $this->assertSame('disabled', TrackingSnapshot::checkedSectionStatus('disabled', 1, 1, false));
    }

    public function testStatusesOffTheSiteAreLeftAlone(): void
    {
        $this->assertSame('available', TrackingSnapshot::checkedSectionStatus('available', 1, 0, false));
    }
}
