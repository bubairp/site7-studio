<?php

namespace site7\studio\tests\unit\services;

use Codeception\Test\Unit;
use site7\studio\services\FrontendToolingScanner;

/**
 * Covers FrontendToolingScanner::detectAt()/captureNpmDependencies() against
 * a real temp directory (Website Starter Kit System Phase 4) - the pure
 * filesystem-detection logic, extracted specifically so it doesn't need a
 * live Craft app (detect()'s only Craft-dependent part, discovering the
 * real project root, isn't exercised here - matching this repo's existing
 * convention, see ManifestReaderTest for the same temp-dir pattern).
 */
class FrontendToolingScannerTest extends Unit
{
    protected \UnitTester $tester;

    private string $tempDir;

    protected function _before()
    {
        $this->tempDir = sys_get_temp_dir() . '/site7_frontend_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function _after()
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data));
    }

    public function testDetectAtReturnsNullWithNoPackageJsonAnywhere()
    {
        $scanner = new FrontendToolingScanner();
        $this->assertNull($scanner->detectAt($this->tempDir));
    }

    public function testDetectAtFindsPackageJsonInTheFrontendSubdirectory()
    {
        mkdir($this->tempDir . '/frontend');
        $this->writeJson($this->tempDir . '/frontend/package.json', ['name' => 'test']);
        touch($this->tempDir . '/frontend/vite.config.mjs');

        $scanner = new FrontendToolingScanner();
        $result = $scanner->detectAt($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame($this->tempDir . '/frontend', $result['root']);
        $this->assertSame('vite', $result['system']);
        $this->assertContains('vite.config.mjs', $result['configFiles']);
        $this->assertContains('package.json', $result['configFiles']);
    }

    public function testDetectAtPrefersProjectRootOverFrontendSubdirectory()
    {
        $this->writeJson($this->tempDir . '/package.json', ['name' => 'root']);
        touch($this->tempDir . '/webpack.config.js');
        mkdir($this->tempDir . '/frontend');
        $this->writeJson($this->tempDir . '/frontend/package.json', ['name' => 'nested']);

        $scanner = new FrontendToolingScanner();
        $result = $scanner->detectAt($this->tempDir);

        $this->assertSame($this->tempDir, $result['root']);
        $this->assertSame('webpack', $result['system']);
    }

    public function testDetectAtFallsBackToTailwindDependencyWhenNoTailwindConfigFileExists()
    {
        mkdir($this->tempDir . '/frontend');
        $this->writeJson($this->tempDir . '/frontend/package.json', [
            'devDependencies' => ['tailwindcss' => '^4.0.0'],
        ]);

        $scanner = new FrontendToolingScanner();
        $result = $scanner->detectAt($this->tempDir);

        $this->assertSame('tailwind', $result['system']);
    }

    public function testDetectAtDefaultsToPlainWhenNoKnownSystemDetected()
    {
        mkdir($this->tempDir . '/frontend');
        $this->writeJson($this->tempDir . '/frontend/package.json', ['name' => 'test']);

        $scanner = new FrontendToolingScanner();
        $result = $scanner->detectAt($this->tempDir);

        $this->assertSame('plain', $result['system']);
        $this->assertSame(['package.json'], $result['configFiles']);
    }

    public function testCaptureNpmDependenciesSplitsRegularAndDevDependencies()
    {
        $this->writeJson($this->tempDir . '/package.json', [
            'dependencies' => ['swiper' => '^11.0.4'],
            'devDependencies' => ['vite' => '^5.2.11'],
        ]);

        $scanner = new FrontendToolingScanner();
        $result = $scanner->captureNpmDependencies($this->tempDir);

        $this->assertContains(['name' => 'swiper', 'version' => '^11.0.4', 'dev' => false], $result);
        $this->assertContains(['name' => 'vite', 'version' => '^5.2.11', 'dev' => true], $result);
    }

    public function testCaptureNpmDependenciesReturnsEmptyArrayWithNoPackageJson()
    {
        $scanner = new FrontendToolingScanner();
        $this->assertSame([], $scanner->captureNpmDependencies($this->tempDir));
    }
}
