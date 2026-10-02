<?php

/**
 * Unit suite bootstrap (Codeception via codeception.yml, PHPUnit via
 * phpunit.xml.dist). There is no live Craft app or DB here.
 *
 * Composer's autoloader comes from the plugin's own vendor/ when it's
 * installed standalone, otherwise from the host Craft project that pulls
 * plugins/site7-studio in as a path repository.
 */
foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 3) . '/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

// Yii and Craft are plain class files, not autoloaded. Code under test calls
// Craft::error() and Yii validators call Yii::createObject(), so load both.
// Craft::$app stays null - a test that reaches it must stub it itself.
if (!class_exists('Yii', false)) {
    require_once \Composer\InstalledVersions::getInstallPath('yiisoft/yii2') . '/Yii.php';
}
if (!class_exists('Craft', false)) {
    require_once \Composer\InstalledVersions::getInstallPath('craftcms/cms') . '/src/Craft.php';
}
