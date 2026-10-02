<?php
/**
 * General Configuration
 *
 * All of your system's general configuration settings go in here. You can see a
 * list of the available settings in vendor/craftcms/cms/src/config/GeneralConfig.php.
 *
 * @see \craft\config\GeneralConfig
 */

use craft\config\GeneralConfig;
use craft\helpers\App;

$isDev = App::env('CRAFT_ENVIRONMENT') === 'dev';
$isStaging = App::env('CRAFT_ENVIRONMENT') === 'staging';
$isProd = App::env('CRAFT_ENVIRONMENT') === 'production';

return GeneralConfig::create()
    // Set the default week start day for date pickers (0 = Sunday, 1 = Monday, etc.)
    ->defaultWeekStartDay(1)
    // Prevent generated URLs from including "index.php"
    ->omitScriptNameInUrls()
    // Preload Single entries as Twig variables
    ->preloadSingles()
    // Prevent user enumeration attacks
    ->preventUserEnumeration()
    // Enable Dev Mode on the dev environment (see https://craftcms.com/guides/what-dev-mode-does)
    ->devMode($isDev)
    // Only allow administrative changes on dev environments
    ->allowAdminChanges($isDev)
    // Disallow robots everywhere except the production environment
    ->disallowRobots(!$isProd)
    // Enable template caching only on production
    ->enableTemplateCaching($isProd)
    // Disable updates on production (allow updates on non-production)
    ->allowUpdates(!$isProd)
    //Set control panel trigger
    ->cpTrigger('admin')
    //Set same site cookie value
    ->sameSiteCookieValue('Lax')
    //Generate transforms
    ->generateTransformsBeforePageLoad(true)
    // Set the web alias
    ->aliases([
        '@webroot' => dirname(__DIR__) . '/web',
        '@web' => App::env('PRIMARY_SITE_URL'),
    ])
    ->maxUploadFileSize(52428800)
;
