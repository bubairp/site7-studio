<?php

namespace site7\studio\tests\unit\services\synchronization;

use Codeception\Test\Unit;
use site7\studio\services\synchronization\BaselinePathRepair;

/**
 * The migration that repairs block-template tracking rows saved as
 * "_blocks/x.twig" by Library updates before docs/43 #67 was fixed.
 */
class BaselinePathRepairTest extends Unit
{
    protected \UnitTester $tester;

    private const LIVE = 'live-checksum';

    public function testAWrongRowAloneMovesToTheRightPath(): void
    {
        $ops = BaselinePathRepair::plan([$this->row(1, '_blocks/x.twig', self::LIVE)], fn() => self::LIVE);

        $this->assertSame([['op' => 'update', 'id' => 1, 'set' => ['targetPath' => 'templates/_blocks/x.twig']]], $ops);
    }

    public function testTheRowMatchingTheFileOnDiskWins(): void
    {
        // Typical case: install wrote the right row (old version), an update wrote the wrong one (new version).
        $right = $this->row(1, 'templates/_blocks/x.twig', 'old', '1.0.0', '2026-10-01 00:00:00');
        $wrong = $this->row(2, '_blocks/x.twig', self::LIVE, '1.0.1', '2026-10-20 00:00:00');

        $ops = BaselinePathRepair::plan([$right, $wrong], fn() => self::LIVE);

        $this->assertSame([
            ['op' => 'update', 'id' => 1, 'set' => ['checksum' => self::LIVE, 'installedVersion' => '1.0.1', 'resourceHandle' => 'x']],
            ['op' => 'delete', 'id' => 2],
        ], $ops);
    }

    public function testAStaleWrongRowNeverOverwritesACorrectRow(): void
    {
        // The right row was rewritten later (reinstall) and matches the file; the wrong row is stale but newer by date.
        $right = $this->row(1, 'templates/_blocks/x.twig', self::LIVE, '1.0.2', '2026-10-01 00:00:00');
        $wrong = $this->row(2, '_blocks/x.twig', 'stale', '1.0.1', '2026-10-20 00:00:00');

        $this->assertSame([['op' => 'delete', 'id' => 2]], BaselinePathRepair::plan([$right, $wrong], fn() => self::LIVE));
    }

    public function testWithoutAMatchTheNewerRowWinsAndATieKeepsTheRightRow(): void
    {
        $right = $this->row(1, 'templates/_blocks/x.twig', 'a', '1.0.0', '2026-10-01 00:00:00');
        $wrong = $this->row(2, '_blocks/x.twig', 'b', '1.0.1', '2026-10-20 00:00:00');
        $edited = fn() => 'customer edit';

        $this->assertSame('update', BaselinePathRepair::plan([$right, $wrong], $edited)[0]['op'], 'newer wrong row wins');
        $this->assertSame([['op' => 'delete', 'id' => 2]], BaselinePathRepair::plan([$right, ['dateUpdated' => '2026-10-01 00:00:00'] + $wrong], $edited), 'a tie keeps the right row');
        $this->assertSame('update', BaselinePathRepair::plan([$right, $wrong], fn() => null)[0]['op'], 'file missing: neither matches, the newer row wins');
    }

    public function testAnEmptyChecksumNeverWins(): void
    {
        $right = $this->row(1, 'templates/_blocks/x.twig', 'a', '1.0.0', '2026-10-01 00:00:00');
        $failed = $this->row(2, '_blocks/x.twig', '', '1.0.1', '2026-10-20 00:00:00');

        $this->assertSame([['op' => 'delete', 'id' => 2]], BaselinePathRepair::plan([$right, $failed], fn() => 'other'));

        $emptyRight = $this->row(1, 'templates/_blocks/x.twig', '', '1.0.0', '2026-10-20 00:00:00');
        $real = $this->row(2, '_blocks/x.twig', 'b', '1.0.1', '2026-10-01 00:00:00');
        $this->assertSame('update', BaselinePathRepair::plan([$emptyRight, $real], fn() => 'other')[0]['op']);
    }

    public function testOtherRowsAndOtherPackagesAreLeftAlone(): void
    {
        $rows = [
            $this->row(1, 'templates/_blocks/x.twig', 'a'),
            $this->row(2, 'frontend/src/css/_blocks/x.twig', 'a'),
            $this->row(3, '_blocks/nested/x.twig', 'a'),
            ['packageId' => 9] + $this->row(4, '_blocks/x.twig', 'a'),
        ];

        $this->assertSame([['op' => 'update', 'id' => 4, 'set' => ['targetPath' => 'templates/_blocks/x.twig']]], BaselinePathRepair::plan($rows, fn() => 'a'),
            'only a package\'s own wrong rows; a wrong row of package 9 has no right row in package 9');
    }

    public function testRunningAgainFindsNothingToDo(): void
    {
        $rows = [$this->row(1, 'templates/_blocks/x.twig', self::LIVE), $this->row(5, 'templates/_blocks/y.twig', 'b')];

        $this->assertSame([], BaselinePathRepair::plan($rows, fn() => self::LIVE));
    }

    /** Rows an update left at an older version (seen on site7-qa: blogs, teams, testimonials, portfolios) */
    public function testAStaleRowIsRefreshedOnlyWhenTheFileIsTheInstalledVersionsOwn(): void
    {
        $rows = [
            $this->row(1, 'templates/_blocks/a.twig', 'v1', '1.0.1'), // file = installed version's: refreshed
            $this->row(2, 'templates/_blocks/b.twig', 'v1', '1.0.1'), // file edited here: left alone
            $this->row(3, 'templates/_blocks/c.twig', 'v3', '1.0.3'), // already right: nothing to do
            $this->row(4, 'templates/_blocks/d.twig', 'v1', '1.0.1'), // file missing: left alone
            $this->row(5, 'frontend/src/css/e.css', 'v1', '1.0.1'),   // not a block template: left alone
        ];
        $live = ['templates/_blocks/a.twig' => 'v3', 'templates/_blocks/b.twig' => 'edited', 'templates/_blocks/c.twig' => 'v3', 'frontend/src/css/e.css' => 'v3'];

        $ops = BaselinePathRepair::refreshPlan($rows, fn($p) => $live[$p] ?? null, fn() => ['checksum' => 'v3', 'version' => '1.0.3']);

        $this->assertSame([['op' => 'update', 'id' => 1, 'set' => ['checksum' => 'v3', 'installedVersion' => '1.0.3']]], $ops);
        $this->assertSame([], BaselinePathRepair::refreshPlan($rows, fn($p) => $live[$p] ?? null, fn() => null), 'installed version unknown: nothing changes');
    }

    private function row(int $id, string $path, string $checksum, string $version = '1.0.0', string $date = '2026-10-10 00:00:00'): array
    {
        return ['id' => $id, 'packageId' => 7, 'targetPath' => $path, 'checksum' => $checksum, 'installedVersion' => $version,
            'resourceHandle' => basename($path, '.twig'), 'dateUpdated' => $date];
    }
}
