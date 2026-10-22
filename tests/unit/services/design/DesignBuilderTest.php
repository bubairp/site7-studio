<?php

namespace site7\studio\tests\unit\services\design;

use Codeception\Test\Unit;
use site7\studio\services\design\DesignBuilder;

class DesignBuilderTest extends Unit
{
    protected \UnitTester $tester;

    private string $dir;

    protected function _before(): void
    {
        $this->dir = sys_get_temp_dir() . '/design-builder-test-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function _after(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testDesignsCarryNoServerCode(): void
    {
        foreach (['index.php', 'x.phtml', 'shell.php.txt', '.htaccess', '.user.ini', 'web.config', 'run.sh', 'A.PHP'] as $name) {
            $this->assertFalse(DesignBuilder::isAllowedFile($name), $name);
        }
        foreach (['header.twig', 'canopy.css', 'index.DD5So89Z.css', 'manifest.json', 'DESIGN.md', 'font.woff2', 'phpinfo-notes.md'] as $name) {
            $this->assertTrue(DesignBuilder::isAllowedFile($name), $name);
        }
    }

    public function testForbiddenFilesAreFoundAnywhere(): void
    {
        $this->put('templates/_blocks/cta.twig', 'x');
        $this->put('templates/deep/evil.php', '<?php');
        $this->put('node_modules/pkg/install.sh', 'ignored: never copied');
        symlink('/etc/passwd', "{$this->dir}/templates/link.twig");

        $bad = DesignBuilder::forbiddenFiles($this->dir);
        sort($bad);
        $this->assertSame(['templates/deep/evil.php', 'templates/link.twig'], $bad);
    }

    public function testOverridesRecordTheBaseTheyReplace(): void
    {
        $this->put('design/_blocks/cta.twig', 'design copy');
        $this->put('design/_components/new.twig', 'only in the design');
        $this->put('design/.gitkeep', '');
        $this->put('site/_blocks/cta.twig', 'site original');

        $overrides = DesignBuilder::overrides("{$this->dir}/design", "{$this->dir}/site");
        $this->assertSame(['_blocks/cta.twig' => sha1('site original'), '_components/new.twig' => null], $overrides);
    }

    public function testEntriesOfAViteManifest(): void
    {
        $this->put('manifest.json', json_encode([
            'src/index.js' => ['file' => 'js/index.js', 'isEntry' => true],
            'src/icons.js' => ['file' => 'js/icons.js', 'isEntry' => true],
            '_chunk.js' => ['file' => 'js/chunk.js'],
        ]));
        $this->assertSame(['src/icons.js', 'src/index.js'], DesignBuilder::entries("{$this->dir}/manifest.json"));
        $this->assertSame([], DesignBuilder::entries("{$this->dir}/missing.json"));
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
