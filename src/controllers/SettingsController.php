<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;

class SettingsController extends Controller
{
    public function actionIndex()
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\SettingsBundle::class);

        return $this->renderTemplate('site7-studio/settings', [
            'title' => 'Settings',
            'settings' => Site7Studio::getInstance()->getSettings(),
            'system' => $this->getSystemInfo(),
            'links' => $this->getAboutLinks(),
        ]);
    }

    /**
     * About tab links, read from composer.json's standard "homepage"/
     * "support" fields rather than hardcoded - those don't exist yet for
     * this plugin, so the About tab shows "Not yet available" until they're
     * added there.
     */
    private function getAboutLinks(): array
    {
        $composerPath = dirname(Site7Studio::getInstance()->getBasePath()) . '/composer.json';
        $composer = is_file($composerPath) ? json_decode(file_get_contents($composerPath), true) : [];

        return [
            'website' => $composer['homepage'] ?? null,
            'documentation' => $composer['support']['docs'] ?? null,
            'support' => $composer['support']['issues'] ?? $composer['support']['email'] ?? null,
            'releaseNotes' => $composer['extra']['changelogUrl'] ?? null,
        ];
    }

    /**
     * Read-only data for the System tab. Everything here comes from Craft's
     * own config/environment or from services this plugin already exposes -
     * nothing is stored by this settings module itself, per the "use Craft's
     * native configuration instead of maintaining our own" requirement.
     */
    private function getSystemInfo(): array
    {
        $plugin = Site7Studio::getInstance();

        return [
            'studioVersion' => $plugin->version,
            'craftVersion' => Craft::$app->getVersion(),
            'phpVersion' => PHP_VERSION,
            'environment' => Craft::$app->env,
            'devMode' => Site7Studio::isDevMode(),
            'installedPackageCount' => count($plugin->packageManager->getAllPackages()),
            'marketplaceConnected' => $plugin->commerceClient->isConfigured(),
            'licenseStatus' => $plugin->license->getLicense()->status,
        ];
    }

    /**
     * Saves the Commerce tab's fields onto the plugin's Settings model,
     * following Craft's own plugin-settings save flow (the same one
     * `plugins/save-plugin-settings` uses) so validation, project config
     * persistence, and cache invalidation all behave identically to any
     * other Craft plugin's settings.
     */
    public function actionSave()
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $plugin = Site7Studio::getInstance();

        $submitted = $request->getBodyParam('settings', []);
        // Textarea input for the offline-features escape hatch - one handle per line.
        if (isset($submitted['commerceOfflineFeatures']) && is_string($submitted['commerceOfflineFeatures'])) {
            $submitted['commerceOfflineFeatures'] = array_values(array_filter(array_map('trim', explode("\n", $submitted['commerceOfflineFeatures']))));
        }

        // Craft's savePluginSettings() only ever persists the keys present in
        // the array it's handed - internally it does
        // $settings->toArray(array_keys($given)), then replaces the plugin's
        // *entire* project config settings node with just that. Since the
        // Commerce tab only ever submits its own 7 fields, passing $submitted
        // alone would silently wipe every other setting (matrixFieldId
        // included) out of project config. Merging onto the full current
        // attribute set means only what's actually in this form changes.
        $data = array_merge($plugin->getSettings()->getAttributes(), $submitted);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $data)) {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Couldn’t save the settings.'));
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice(Craft::t('site7-studio', 'Settings saved.'));
        return $this->redirectToPostedUrl();
    }

    /**
     * A lightweight connectivity check for the Commerce tab's "Test Connection" button.
     */
    public function actionTestConnection()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $client = Site7Studio::getInstance()->commerceClient;

        if (!$client->isConfigured()) {
            return $this->asJson(['success' => false, 'message' => 'Set an API Endpoint and API Key first.']);
        }

        try {
            $client->request('GET', '/ping');
            return $this->asJson(['success' => true, 'message' => 'Connected to Commerce24.']);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
