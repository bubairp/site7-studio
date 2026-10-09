<?php

namespace site7\studio\controllers;

use Craft;
use craft\fields\Matrix;
use craft\web\Controller;
use site7\studio\Site7Studio;

class SettingsController extends Controller
{
    public function actionIndex()
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\SettingsBundle::class);

        $settings = Site7Studio::getInstance()->getSettings();
        $allowAdminChanges = Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
        $overriddenKeys = \site7\studio\models\Settings::overriddenKeys();

        return $this->renderTemplate('site7-studio/settings', [
            'title' => 'Settings',
            'settings' => $settings,
            // Fields not listed in editableKeys render disabled (so they're
            // not submitted); overriddenKeys show the config file's value.
            'allowAdminChanges' => $allowAdminChanges,
            'overriddenKeys' => $overriddenKeys,
            'editableKeys' => \site7\studio\models\Settings::editableKeys(array_keys($settings->getAttributes()), $overriddenKeys, $allowAdminChanges),
            'matrixFields' => array_values(array_filter(Craft::$app->getFields()->getAllFields(), fn($field) => $field instanceof Matrix)),
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
            'uninstallSnapshot' => $this->getUninstallSnapshotInfo(),
        ];
    }

    /**
     * For the System tab's uninstall notice (docs/58): where the snapshot
     * goes, and whether one is waiting to be restored or was restored.
     */
    private function getUninstallSnapshotInfo(): array
    {
        $dir = \site7\studio\services\library\TrackingSnapshot::directory();
        $pending = \site7\studio\services\library\TrackingSnapshot::path();
        $restored = glob("{$dir}/uninstall-snapshot.restored-*.json") ?: [];
        rsort($restored);

        return [
            'path' => 'storage/site7-studio/' . \site7\studio\services\library\TrackingSnapshot::FILE,
            'pendingDate' => is_file($pending) ? date('Y-m-d H:i', (int)filemtime($pending)) : null,
            'lastRestored' => $restored ? basename($restored[0]) : null,
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
        // Also refuses (403) when allowAdminChanges is off: requireAdmin()'s
        // $requireAdminChanges defaults to true. savePluginSettings() writes
        // project config, which such environments only get through git.
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $plugin = Site7Studio::getInstance();

        $submitted = $request->getBodyParam('settings', []);
        // Textarea input for the offline-features escape hatch - one handle per line.
        if (isset($submitted['commerceOfflineFeatures']) && is_string($submitted['commerceOfflineFeatures'])) {
            $submitted['commerceOfflineFeatures'] = array_values(array_filter(array_map('trim', explode("\n", $submitted['commerceOfflineFeatures']))));
        }
        // Textarea input for the package Category dropdown's options - one per line.
        if (isset($submitted['packageCategories']) && is_string($submitted['packageCategories'])) {
            $submitted['packageCategories'] = array_values(array_filter(array_map('trim', explode("\n", $submitted['packageCategories']))));
        }

        // The page builder: an existing Matrix field, or none.
        if (array_key_exists('matrixFieldUid', $submitted)) {
            $submitted['matrixFieldUid'] = $submitted['matrixFieldUid'] ?: null;
            if ($submitted['matrixFieldUid'] !== null && !Craft::$app->getFields()->getFieldByUid($submitted['matrixFieldUid']) instanceof Matrix) {
                Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Choose a Matrix field.'));
                return $this->redirectToPostedUrl();
            }
            // Enabled Section packages are linked into the current field;
            // switching would strand their blocks there.
            if ($submitted['matrixFieldUid'] !== ($plugin->getSettings()->matrixFieldUid ?: null)) {
                $linked = array_filter($plugin->packageManager->getAllPackages(), fn($record) => $record->type === 'section' && $record->status === 'enabled');
                if ($linked) {
                    Craft::$app->getSession()->setError(Craft::t('site7-studio', 'The page builder can’t change while {count} Section packages are enabled in it. Disable them first, or keep this page builder.', ['count' => count($linked)]));
                    return $this->redirectToPostedUrl();
                }
            }
        }

        // The Commerce tab only submits its own fields; see
        // Settings::mergeWithStored() for why they're merged onto the stored
        // settings (not getAttributes(), which carries .env overrides).
        $data = \site7\studio\models\Settings::mergeWithStored($submitted);

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
        // Calls Commerce24 with the stored API key: same audience as saving settings.
        $this->requireAdmin(false);

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
