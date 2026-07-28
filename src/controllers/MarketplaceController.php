<?php

namespace site7\studio\controllers;

use Craft;
use craft\helpers\FileHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use site7\studio\models\marketplace\PackageValidationResult;
use site7\studio\services\PackageExportService;
use site7\studio\services\PackageImportService;
use site7\studio\Site7Studio;

/**
 * Backs the Marketplace: Installed / Import / Export / Updates / Repository
 * tabs. Import's multi-step flow (Select -> Validate -> Preview -> Install)
 * spans two requests - actionImportUpload() validates an uploaded archive and
 * stashes the resulting PackageValidationResult (including its extracted
 * temp directory) in the CP session; actionImportInstall() reads it back to
 * perform the actual install once the user confirms from the Preview.
 */
class MarketplaceController extends Controller
{
    private const SESSION_KEY = 'site7Studio.pendingImport';

    private const TABS = ['installed', 'import', 'export', 'updates', 'repository'];

    /**
     * The Marketplace index, one of five tabs.
     */
    public function actionIndex()
    {
        $tab = (string)Craft::$app->getRequest()->getQueryParam('tab', 'installed');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'installed';
        }

        $marketplace = Site7Studio::getInstance()->marketplace;
        $packageManager = Site7Studio::getInstance()->packageManager;

        $data = [
            'title' => 'Marketplace',
            'activeTab' => $tab,
        ];

        switch ($tab) {
            case 'installed':
                $data['packages'] = $packageManager->getAllPackages();
                $data['updates'] = $marketplace->checkForUpdates();
                break;
            case 'export':
                $data['packages'] = $packageManager->getAllPackages();
                break;
            case 'import':
                $data['pendingImport'] = Craft::$app->getSession()->get(self::SESSION_KEY);
                break;
            case 'updates':
                $data['updates'] = $marketplace->checkForUpdates();
                break;
            case 'repository':
                $data['repositories'] = $marketplace->getRepositories();
                // Fetched once here (each repository's listAvailablePackages()
                // can mean real I/O - a live Commerce24 API call, or extracting
                // every .s7pkg in the Local Repository) and passed down keyed
                // by repository handle, so the template never has to call it
                // again itself.
                $data['listingsByRepository'] = [];
                foreach ($data['repositories'] as $handle => $repository) {
                    $data['listingsByRepository'][$handle] = $repository->listAvailablePackages();
                }
                $data['commerceOwnership'] = $this->buildCommerceOwnership($data['listingsByRepository']['commerce24'] ?? []);
                break;
        }

        return $this->renderTemplate('site7-studio/marketplace/index', $data);
    }

    /**
     * Generates and streams a .s7pkg download for a package.
     */
    public function actionExport()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageMarketplace');

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('handle');
        $includeDependencies = (bool)$request->getBodyParam('includeDependencies', true);

        try {
            $path = (new PackageExportService())->exportPackage($handle, $includeDependencies);
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Export failed: ' . $e->getMessage());
            return $this->redirect('site7-studio/marketplace?tab=export');
        }

        return Craft::$app->getResponse()->sendFile($path, basename($path));
    }

    /**
     * Step 1-2 of Import: accepts an uploaded .s7pkg, validates it, and
     * stashes the result for the Preview step rendered back on the same tab.
     */
    public function actionImportUpload()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageMarketplace');

        $file = UploadedFile::getInstanceByName('packageFile');
        if (!$file) {
            Craft::$app->getSession()->setError('Choose a .s7pkg file to upload.');
            return $this->redirect('site7-studio/marketplace?tab=import');
        }

        $uploadDir = Craft::getAlias('@storage') . '/runtime/site7-studio/uploads';
        FileHelper::createDirectory($uploadDir);
        $tempUploadPath = $uploadDir . '/' . uniqid('', true) . '.s7pkg';
        $file->saveAs($tempUploadPath);

        $validation = (new PackageImportService())->validatePackage($tempUploadPath);
        @unlink($tempUploadPath);

        $this->discardPendingImport();
        Craft::$app->getSession()->set(self::SESSION_KEY, $validation);

        return $this->redirect('site7-studio/marketplace?tab=import');
    }

    /**
     * Step 3 of Import: confirms and performs the install of the archive
     * validated by actionImportUpload().
     */
    public function actionImportInstall()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageMarketplace');

        $validation = Craft::$app->getSession()->get(self::SESSION_KEY);
        if (!$validation instanceof PackageValidationResult || !$validation->valid) {
            Craft::$app->getSession()->setError('There is no valid pending import to install.');
            return $this->redirect('site7-studio/marketplace?tab=import');
        }

        $request = Craft::$app->getRequest();
        $options = [
            'overwriteConflicts' => (bool)$request->getBodyParam('overwriteConflicts', false),
            'install' => (bool)$request->getBodyParam('install', true),
            'enable' => (bool)$request->getBodyParam('enable', true),
        ];

        try {
            $summary = (new PackageImportService())->importPackage($validation, $options);
            Craft::$app->getSession()->remove(self::SESSION_KEY);

            $message = 'Import complete.';
            if (!empty($summary['installed'])) {
                $message .= ' Installed: ' . implode(', ', $summary['installed']) . '.';
            }
            if (!empty($summary['skipped'])) {
                $message .= ' Skipped: ' . implode(', ', $summary['skipped']) . '.';
            }
            if (!empty($summary['errors'])) {
                Craft::$app->getSession()->setError($message . ' Errors: ' . implode(', ', $summary['errors']));
            } else {
                Craft::$app->getSession()->setNotice($message);
            }
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Import failed: ' . $e->getMessage());
        }

        return $this->redirect('site7-studio/marketplace?tab=installed');
    }

    /**
     * Discards a pending (validated but not yet installed) import.
     */
    public function actionImportCancel()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageMarketplace');
        $this->discardPendingImport();
        return $this->redirect('site7-studio/marketplace?tab=import');
    }

    /**
     * Fetches and installs the newest available version of a package from
     * whichever repository has it.
     */
    public function actionUpdatePackage()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageMarketplace');
        $handle = (string)Craft::$app->getRequest()->getRequiredBodyParam('handle');

        try {
            Site7Studio::getInstance()->marketplace->updatePackage($handle);
            Craft::$app->getSession()->setNotice("'{$handle}' was updated.");
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Update failed: ' . $e->getMessage());
        }

        return $this->redirect('site7-studio/marketplace?tab=updates');
    }

    /**
     * The Repository tab's "Install" button: fetches a listed package straight
     * from a specific repository and imports it, whether or not it's
     * currently installed - see MarketplaceService::installFromRepository()'s
     * docblock for why this is distinct from actionUpdatePackage().
     */
    public function actionInstallFromRepository()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageMarketplace');

        $request = Craft::$app->getRequest();
        $repositoryHandle = (string)$request->getRequiredBodyParam('repositoryHandle');
        $handle = (string)$request->getRequiredBodyParam('handle');

        try {
            $summary = Site7Studio::getInstance()->marketplace->installFromRepository($repositoryHandle, $handle);

            $message = "'{$handle}' was installed from {$repositoryHandle}.";
            if (!empty($summary['errors'])) {
                Craft::$app->getSession()->setError($message . ' Errors: ' . implode(', ', $summary['errors']));
            } else {
                Craft::$app->getSession()->setNotice($message);
            }
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError("Could not install '{$handle}' from {$repositoryHandle}: " . $e->getMessage());
        }

        return $this->redirect('site7-studio/marketplace?tab=repository');
    }

    /**
     * Removes and reinstalls a package's generated Craft resources from its current files on disk.
     */
    public function actionReinstallPackage()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageMarketplace');
        $handle = (string)Craft::$app->getRequest()->getRequiredBodyParam('handle');

        $success = Site7Studio::getInstance()->marketplace->reinstallPackage($handle);
        $success
            ? Craft::$app->getSession()->setNotice("'{$handle}' was reinstalled.")
            : Craft::$app->getSession()->setError("Could not reinstall '{$handle}'.");

        return $this->redirect('site7-studio/marketplace?tab=installed');
    }

    /**
     * Re-syncs a package's DB record and regenerates any missing Craft resources.
     */
    public function actionRepairPackage()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageMarketplace');
        $handle = (string)Craft::$app->getRequest()->getRequiredBodyParam('handle');

        $success = Site7Studio::getInstance()->marketplace->repairPackage($handle);
        $success
            ? Craft::$app->getSession()->setNotice("'{$handle}' was repaired.")
            : Craft::$app->getSession()->setError("Could not repair '{$handle}'.");

        return $this->redirect('site7-studio/marketplace?tab=installed');
    }

    private function discardPendingImport(): void
    {
        $session = Craft::$app->getSession();
        $previous = $session->get(self::SESSION_KEY);
        if ($previous instanceof PackageValidationResult && $previous->tempDir && is_dir($previous->tempDir)) {
            FileHelper::removeDirectory($previous->tempDir);
        }
        $session->remove(self::SESSION_KEY);
    }

    /**
     * Ownership/entitlement info for the Repository tab's Commerce24 listings
     * only - the Local Repository has no such concept, so its rows just show
     * nothing for this. Mirrors the same three-way split Commerce &
     * Licensing's Packages tab already uses (isEntitled() first, then which
     * plans would cover it if not), so a listing here reads the same way it
     * would there - this is what MarketplaceService::installFromRepository()'s
     * own entitlement gate is checking before it lets Install actually run.
     *
     * @param \site7\studio\models\marketplace\MarketplaceListing[] $commerce24Listings
     * @return array<string, array{label: string, color: string, plans: string[]}>
     */
    private function buildCommerceOwnership(array $commerce24Listings): array
    {
        if (empty($commerce24Listings)) {
            return [];
        }

        $plugin = Site7Studio::getInstance();
        $purchasedHandles = $plugin->commercePackages->getPurchasedPackages();
        $freeHandles = $plugin->commercePackages->getFreePackages();
        $allPlans = $plugin->plan->getAllPlans();

        $ownership = [];
        foreach ($commerce24Listings as $listing) {
            $handle = $listing->handle;

            if (in_array($handle, $purchasedHandles, true)) {
                $ownership[$handle] = ['label' => 'Purchased', 'color' => 'teal', 'plans' => []];
            } elseif (in_array($handle, $freeHandles, true)) {
                $ownership[$handle] = ['label' => 'Free', 'color' => 'gray', 'plans' => []];
            } elseif ($plugin->commercePackages->isEntitled($handle)) {
                $ownership[$handle] = ['label' => 'Included in Your Plan', 'color' => 'teal', 'plans' => []];
            } else {
                $plans = array_values(array_map(
                    fn($planInfo) => $planInfo->name,
                    array_filter($allPlans, fn($planInfo) => in_array($handle, $planInfo->includedPackages, true))
                ));
                $ownership[$handle] = ['label' => 'Locked', 'color' => 'amber', 'plans' => $plans];
            }
        }

        return $ownership;
    }
}
