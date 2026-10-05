<?php

namespace site7\studio\tests\unit\services;

use Codeception\Test\Unit;
use site7\studio\services\PackageManagerService;

class OwnedFilePathTest extends Unit
{
    protected \UnitTester $tester;

    public function testFrontendSourcesAreAllowed(): void
    {
        foreach (['src/css/app.css', 'frontend/src/js/main.js', 'assets/src/img/logo.svg', 'theme/src/css/theme.css'] as $path) {
            $this->assertTrue(PackageManagerService::isAllowedOwnedFileTarget($path), $path);
        }
    }

    public function testEverythingElseIsRefused(): void
    {
        foreach ([
            'web/shell.php',
            'config/db.php',
            '.env',
            'templates/_blocks/x.twig',
            'frontend/src/../../config/general.php',
            '../frontend/src/x.css',
            '/var/www/html/frontend/src/x.css',
            'C:/x/frontend/src/x.css',
            'frontend\\src\\x.css',
            'frontend/src/x.php',
            'src/evil.phtml',
            'frontend/src/./x.css',
            'frontend//src/x.css',
            'src/',
            '',
        ] as $path) {
            $this->assertFalse(PackageManagerService::isAllowedOwnedFileTarget($path), $path);
        }
    }

    public function testRelativeSafePath(): void
    {
        $this->assertTrue(PackageManagerService::isRelativeSafePath('templates/_blocks/hero.twig'));
        $this->assertTrue(PackageManagerService::isRelativeSafePath('owned/css/app.css'));
        $this->assertFalse(PackageManagerService::isRelativeSafePath('templates/../config/db.php'));
        $this->assertFalse(PackageManagerService::isRelativeSafePath('/etc/passwd'));
        $this->assertFalse(PackageManagerService::isRelativeSafePath('a\\b'));
    }
}
