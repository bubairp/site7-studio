<?php

namespace site7\studio\tests\unit\services\theme;

use Codeception\Test\Unit;
use site7\studio\services\theme\ThemeOverrides;

/**
 * Overrides of the base theme package on real files in a temporary folder
 * (docs/60 §3): the copy command, its ledger and the review states.
 */
class ThemeOverridesTest extends Unit
{
    protected \UnitTester $tester;

    private string $dir;

    protected function _before(): void
    {
        $this->dir = sys_get_temp_dir() . '/s7-overrides-' . uniqid();
        $this->put('theme/_blocks/ctaBanner.twig', 'cta v1');
        $this->put('theme/_layouts/base.twig', 'layout v1');
        $this->put('site/_blocks/accordion.twig', 'an extra block: the theme has none');
    }

    protected function _after(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testCopyPutsTheThemesFileInTemplatesAndRecordsIt(): void
    {
        $path = $this->overrides()->copy('_blocks/ctaBanner');

        $this->assertSame('_blocks/ctaBanner.twig', $path, '.twig may be left off');
        $this->assertSame('cta v1', file_get_contents("{$this->dir}/site/_blocks/ctaBanner.twig"));
        $ledger = json_decode(file_get_contents("{$this->dir}/site/" . ThemeOverrides::LEDGER), true);
        $this->assertSame(sha1('cta v1'), $ledger['_blocks/ctaBanner.twig']['checksum']);
        $this->assertSame('1.8.0', $ledger['_blocks/ctaBanner.twig']['theme']);
        $this->assertSame(['_blocks/ctaBanner.twig' => ['state' => 'current', 'theme' => '1.8.0', 'copied' => date('Y-m-d')]], $this->overrides()->report());
        $this->assertSame([], $this->overrides()->needsReview());
    }

    public function testCopyRefusesAnExistingCopyAMissingFileAndPathsOutsideTemplates(): void
    {
        $this->put('site/_layouts/base.twig', 'already mine');
        foreach (['_layouts/base.twig' => 'already exists', '_blocks/nope.twig' => 'has no', '../config/db.php' => 'Not a template', '_site7/overrides.json' => 'Not a template'] as $path => $message) {
            try {
                $this->overrides()->copy($path);
                $this->fail("{$path} must be refused");
            } catch (\Exception $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $path);
            }
        }
        $this->assertSame('already mine', file_get_contents("{$this->dir}/site/_layouts/base.twig"), 'never overwritten');
    }

    public function testATemplateTheThemeChangedOrRemovedSinceItWasCopiedIsReported(): void
    {
        $this->overrides()->copy('_blocks/ctaBanner.twig');
        $this->overrides()->copy('_layouts/base.twig');
        file_put_contents("{$this->dir}/site/_blocks/ctaBanner.twig", 'my edit');
        $this->put('theme/_blocks/ctaBanner.twig', 'cta v2');
        unlink("{$this->dir}/theme/_layouts/base.twig");

        $report = $this->overrides()->report();

        $this->assertSame('changed', $report['_blocks/ctaBanner.twig']['state'], 'the theme changed it: the edit hides that');
        $this->assertSame('removed', $report['_layouts/base.twig']['state']);
        $this->assertCount(2, $this->overrides()->needsReview());
    }

    public function testACopyMadeByHandIsUntrackedAndADeletedCopyIsNoOverride(): void
    {
        $this->overrides()->copy('_blocks/ctaBanner.twig');
        unlink("{$this->dir}/site/_blocks/ctaBanner.twig");
        $this->put('site/_layouts/base.twig', 'copied by hand');

        $this->assertSame(['_layouts/base.twig' => ['state' => 'untracked', 'theme' => null, 'copied' => null]], $this->overrides()->report(), 'site files the theme lacks (extra blocks) aren\'t overrides');
    }

    public function testASiteWithoutTheThemeHasNoOverrides(): void
    {
        $this->assertSame([], (new ThemeOverrides("{$this->dir}/site", null))->report());
        $this->expectExceptionMessage('no site7/theme package');
        (new ThemeOverrides("{$this->dir}/site", null))->copy('_blocks/ctaBanner.twig');
    }

    private function overrides(): ThemeOverrides
    {
        return new ThemeOverrides("{$this->dir}/site", "{$this->dir}/theme", '1.8.0');
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
