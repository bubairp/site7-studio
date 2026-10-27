<?php

namespace site7\studio\tests\unit\services\theme;

use Codeception\Test\Unit;
use site7\studio\services\theme\ThemeMigration;
use site7\studio\services\theme\ThemeOverrides;

/**
 * Moving an existing site onto the site7/theme package, on real files in a
 * temporary folder (docs/60 §3): copies that match the theme go, edits stay
 * as overrides, older unedited block copies wait for a Library update.
 */
class ThemeMigrationTest extends Unit
{
    protected \UnitTester $tester;

    private string $dir;
    /** @var array<string, array{packageId: int, checksum: string}> tracking rows by path from the Craft root */
    private array $rows = [];

    protected function _before(): void
    {
        $this->dir = sys_get_temp_dir() . '/s7-migrate-' . uniqid();
        $this->put('theme/_blocks/ctaBanner.twig', 'cta v2');
        $this->put('theme/_blocks/services.twig', 'services v2');
        $this->put('theme/_blocks/teams.twig', 'teams v2');
        $this->put('theme/_layouts/base.twig', 'layout v2');
        $this->put('theme/_includes/footer.twig', 'footer v2');
    }

    protected function _after(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testEachCopyGetsTheRightAction(): void
    {
        $this->block('_blocks/ctaBanner.twig', 'cta v2', 'cta v2');          // installed = theme's
        $this->block('_blocks/services.twig', 'services v1', 'services v1'); // as installed, older
        $this->block('_blocks/teams.twig', 'teams v1', 'teams v1 + my edit'); // edited here
        $this->put('site/_layouts/base.twig', 'layout v2');                  // Theme file, same
        $this->put('site/_includes/footer.twig', 'my footer');               // Theme file kept as edited
        $this->put('site/_blocks/accordion.twig', 'an extra block');         // not the theme's

        $this->assertSame([
            '_blocks/ctaBanner.twig' => ThemeMigration::REMOVE,
            '_blocks/services.twig' => ThemeMigration::WAIT,
            '_blocks/teams.twig' => ThemeMigration::OVERRIDE,
            '_includes/footer.twig' => ThemeMigration::OVERRIDE,
            '_layouts/base.twig' => ThemeMigration::REMOVE,
        ], $this->migration()->plan());
    }

    public function testARunDeletesRecordsAndKeepsTheRest(): void
    {
        $this->block('_blocks/ctaBanner.twig', 'cta v2', 'cta v2');
        $this->block('_blocks/services.twig', 'services v1', 'services v1');
        $this->put('site/_includes/footer.twig', 'my footer');
        $this->put('site/_layouts/base.twig', 'layout v2');
        $this->put('site/_blocks/accordion.twig', 'an extra block');

        $report = $this->migration()->run();

        $this->assertSame(['_blocks/ctaBanner.twig', '_layouts/base.twig'], $report['removed']);
        $this->assertSame(['_blocks/services.twig'], $report['waiting']);
        $this->assertSame(['_includes/footer.twig'], $report['overrides']);
        $this->assertFileDoesNotExist("{$this->dir}/site/_blocks/ctaBanner.twig");
        $this->assertDirectoryDoesNotExist("{$this->dir}/site/_layouts", 'an emptied folder goes');
        $this->assertSame('my footer', file_get_contents("{$this->dir}/site/_includes/footer.twig"));
        $this->assertSame('an extra block', file_get_contents("{$this->dir}/site/_blocks/accordion.twig"));
        $this->assertArrayNotHasKey('templates/_blocks/ctaBanner.twig', $this->rows, 'the deleted copy\'s tracking row goes');
        $this->assertArrayHasKey('templates/_blocks/services.twig', $this->rows);

        $overrides = $this->overrides()->report();
        $this->assertSame('current', $overrides['_includes/footer.twig']['state'], 'recorded against the theme\'s file now');
        $this->assertSame('untracked', $overrides['_blocks/services.twig']['state'], 'a waiting copy isn\'t an override');
    }

    public function testARecordedOverrideIsLeftAloneAndASecondRunHasNothingLeft(): void
    {
        $this->overrides()->copy('_blocks/ctaBanner.twig'); // the same bytes as the theme's, but chosen
        $this->put('site/_layouts/base.twig', 'layout v2');

        $this->migration()->run();

        $this->assertFileExists("{$this->dir}/site/_blocks/ctaBanner.twig", 'an override made with theme/override stays');
        $this->assertSame([], $this->migration()->plan());
    }

    public function testASiteWithoutThePackageIsToldToUpdateTheThemeFirst(): void
    {
        $this->expectExceptionMessage('update the Theme first');
        (new ThemeMigration("{$this->dir}/site", null, new ThemeOverrides("{$this->dir}/site", null), fn() => null, fn() => null))->plan();
    }

    private function migration(): ThemeMigration
    {
        return new ThemeMigration(
            "{$this->dir}/site",
            "{$this->dir}/theme",
            $this->overrides(),
            fn(string $path) => $this->rows[$path] ?? null,
            function(int $packageId, string $path): void {
                unset($this->rows[$path]);
            },
        );
    }

    private function overrides(): ThemeOverrides
    {
        return new ThemeOverrides("{$this->dir}/site", "{$this->dir}/theme", '1.8.0');
    }

    /** A block template a Section package installed as $installed; the site now has $live. */
    private function block(string $path, string $installed, string $live): void
    {
        $this->put("site/{$path}", $live);
        $this->rows["templates/{$path}"] = ['packageId' => 7, 'checksum' => hash('sha256', $installed)];
    }

    private function put(string $relative, string $contents): void
    {
        $path = "{$this->dir}/{$relative}";
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }
}
