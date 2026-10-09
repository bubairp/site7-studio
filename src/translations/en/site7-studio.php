<?php
/**
 * Site7 Studio plugin for Craft CMS
 *
 * Site7 Studio Translation
 *
 * Returns an array with the string to be translated (as passed to `Craft::t('site7-studio', '...')`) as
 * the key, and the translation as the value.
 */

return [
    'Site7 Studio plugin loaded' => 'Site7 Studio plugin loaded',
    'Commerce24 Webhook Secret' => 'Commerce24 Webhook Secret',
    'The secret key used to validate incoming webhooks from Commerce24.' => 'The secret key used to validate incoming webhooks from Commerce24.',
    'Default Package' => 'Default Package',
    'The default fallback package if a user has no active subscription.' => 'The default fallback package if a user has no active subscription.',
    // Uninstall / reinstall (docs/58)
    'Uninstalling keeps your site and content. What Site7 Studio tracks is saved to storage/site7-studio and restored when you install it again; licences come back from Commerce24.' => 'Uninstalling keeps your site and content. What Site7 Studio tracks is saved to storage/site7-studio and restored when you install it again; licences come back from Commerce24.',
    'Uninstalling Site7 Studio' => 'Uninstalling Site7 Studio',
    'Uninstalling the plugin (Settings → Plugins) keeps your website: sections, fields, blocks, the page builder, entries, assets and templates stay as they are.' => 'Uninstalling the plugin (Settings → Plugins) keeps your website: sections, fields, blocks, the page builder, entries, assets and templates stay as they are.',
    'It removes the plugin\'s own tables - which packages are installed, their versions and file checksums, import links and sync history. Just before that, Site7 Studio saves them to {path}, and restores them automatically when you install the plugin again, so Library updates keep working and packages aren\'t installed twice.' => 'It removes the plugin\'s own tables - which packages are installed, their versions and file checksums, import links and sync history. Just before that, Site7 Studio saves them to {path}, and restores them automatically when you install the plugin again, so Library updates keep working and packages aren\'t installed twice.',
    'Licences and entitlements are not saved: they come back from Commerce24 once the plugin is connected again. Without a snapshot, run php craft site7-studio/library/reconcile to match the Library to the blocks already on the site.' => 'Licences and entitlements are not saved: they come back from Commerce24 once the plugin is connected again. Without a snapshot, run php craft site7-studio/library/reconcile to match the Library to the blocks already on the site.',
    'A snapshot from {date} is waiting to be restored.' => 'A snapshot from {date} is waiting to be restored.',
    'Last restored: {file}' => 'Last restored: {file}',
    'A Theme or Starter Kit sets up the whole site, so it can\'t be reinstalled: update it from Updates instead.' => 'A Theme or Starter Kit sets up the whole site, so it can\'t be reinstalled: update it from Updates instead.',
    // Settings screen read-only states (docs/29, docs/55 §1)
    'Set in config/site7-studio.php - edit it there (or its .env value), not here.' => 'Set in config/site7-studio.php - edit it there (or its .env value), not here.',
    'Settings on this environment come from .env, config/site7-studio.php and project config, which change through git - not from this screen.' => 'Settings on this environment come from .env, config/site7-studio.php and project config, which change through git - not from this screen.',
    'Commerce24 is not connected. Set the API Endpoint and API Key in .env or config/site7-studio.php to enable licensing, plans, and package entitlements.' => 'Commerce24 is not connected. Set the API Endpoint and API Key in .env or config/site7-studio.php to enable licensing, plans, and package entitlements.',
    'Not set' => 'Not set',
];
