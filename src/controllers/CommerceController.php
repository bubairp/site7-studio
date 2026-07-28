<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;

/**
 * Backs the Commerce & Licensing Platform's nine tabs (Overview/Plans/
 * Subscription/License/Packages/Downloads/Updates/Team/Account). Every
 * action here only orchestrates - the actual business logic (entitlement
 * checks, plan/feature resolution, caching, HTTP) lives in the commerce
 * services (LicenseService, SubscriptionService, PlanService, PackageService,
 * DownloadService, UpdateService, FeatureGateService), each behind its own
 * interface, none of which this controller constructs directly - all are
 * reached through Site7Studio's service locator (registered by
 * CommerceServiceProvider), exactly like PackageManagerService/marketplace
 * already are.
 */
class CommerceController extends Controller
{
    private const TABS = ['overview', 'plans', 'subscription', 'license', 'packages', 'downloads', 'updates', 'team', 'account'];

    public function actionIndex()
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\Site7StudioBundle::class);

        $tab = (string)Craft::$app->getRequest()->getQueryParam('tab', 'overview');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'overview';
        }

        $plugin = Site7Studio::getInstance();
        $data = ['title' => 'Commerce & Licensing', 'activeTab' => $tab];

        switch ($tab) {
            case 'overview':
                $data['license'] = $plugin->license->getLicense();
                $data['subscription'] = $plugin->subscription->getSubscription();
                // refreshCurrentPlanAndSyncEntitlements() (not plain
                // getCurrentPlan()) so that landing here after a plan change
                // actually takes effect - a downgrade disables whatever
                // premium packages the new plan no longer covers.
                $planResult = $plugin->plan->refreshCurrentPlanAndSyncEntitlements();
                $data['plan'] = $planResult['plan'];
                $this->flashEntitlementChanges($planResult);
                $data['installedPackages'] = $plugin->packageManager->getAllPackages();
                $data['updates'] = $plugin->marketplace->checkForUpdates();
                break;
            case 'plans':
                $data['plans'] = $plugin->plan->getAllPlans();
                $data['currentPlan'] = $plugin->plan->getCurrentPlan();
                break;
            case 'subscription':
                $data['subscription'] = $plugin->subscription->getSubscription();
                break;
            case 'license':
                $data['license'] = $plugin->license->getLicense();
                break;
            case 'packages':
                $planResult = $plugin->plan->refreshCurrentPlanAndSyncEntitlements();
                $this->flashEntitlementChanges($planResult);
                $data['installedPackages'] = $plugin->packageManager->getAllPackages();
                $data['purchasedHandles'] = $plugin->commercePackages->getPurchasedPackages();
                $data['freeHandles'] = $plugin->commercePackages->getFreePackages();
                $data['premiumHandles'] = $plugin->commercePackages->getPremiumPackages();
                $data['pendingDeletions'] = $plugin->commercePackages->getPendingDeletions();

                $allPlans = $plugin->plan->getAllPlans();

                $installedHandles = array_map(fn($record) => $record->handle, $data['installedPackages']);
                // The full Commerce24 catalog, not just what the current plan/
                // purchases cover - every plan's includedPackages too, so a
                // package exclusive to a higher plan still shows up (locked),
                // instead of only appearing once you're already on that plan.
                $catalogHandles = array_merge($data['purchasedHandles'], $data['freeHandles'], $data['premiumHandles']);
                foreach ($allPlans as $planInfo) {
                    $catalogHandles = array_merge($catalogHandles, $planInfo->includedPackages);
                }
                $candidateHandles = array_unique($catalogHandles);
                $notInstalled = array_diff($candidateHandles, $installedHandles);

                // Split by PackageService::isEntitled() - the same check
                // installEntitled() uses, so anything landing in "Available to
                // Install" is guaranteed installable, and anything in "Locked"
                // is guaranteed not.
                $data['availableToInstall'] = [];
                $data['lockedHandles'] = [];
                $data['lockedPlanNames'] = [];
                foreach ($notInstalled as $handle) {
                    if ($plugin->commercePackages->isEntitled($handle)) {
                        $data['availableToInstall'][] = $handle;
                        continue;
                    }
                    $data['lockedHandles'][] = $handle;
                    // Every plan that would unlock this handle, so the UI can
                    // say "Available on: X, Y" instead of a generic "upgrade somewhere."
                    $data['lockedPlanNames'][$handle] = array_values(array_map(
                        fn($planInfo) => $planInfo->name,
                        array_filter($allPlans, fn($planInfo) => in_array($handle, $planInfo->includedPackages, true))
                    ));
                }
                sort($data['availableToInstall']);
                sort($data['lockedHandles']);
                break;
            case 'downloads':
                $data['purchasedPackages'] = $plugin->downloads->getPurchasedPackages();
                $data['downloadHistory'] = $plugin->downloads->getDownloadHistory();
                $data['importHistory'] = $plugin->downloads->getImportHistory();
                $data['exportHistory'] = $plugin->downloads->getExportHistory();
                break;
            case 'updates':
                $data['updates'] = $plugin->updates->checkUpdates();
                break;
            case 'team':
                $data['teamAllowed'] = $plugin->featureGate->allows('teamManagement');
                break;
            case 'account':
                $data['connected'] = $plugin->commerceClient->isConfigured();
                $data['subscription'] = $plugin->subscription->getSubscription();
                $data['customer'] = $plugin->subscription->getCustomer();
                break;
        }

        return $this->renderTemplate('site7-studio/commerce/index', $data);
    }

    // --- Account ---

    /**
     * Disconnects this site from Commerce24 by clearing the Commerce tab's
     * connection settings (API endpoint + key) - the same fields Settings
     * itself saves via SettingsController::actionSave(), reused here so
     * "Disconnect" in Account has one, consistent effect no matter where it's
     * triggered from. Local functionality (Library, Publishing, local
     * package management) keeps working per the Offline Mode requirement -
     * every commerce service already degrades gracefully once
     * CommerceClient::isConfigured() goes false.
     */
    public function actionDisconnectAccount()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageCommerce');

        $plugin = Site7Studio::getInstance();
        $data = array_merge($plugin->getSettings()->getAttributes(), [
            'commerceApiEndpoint' => null,
            'commerceApiKey' => null,
        ]);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $data)) {
            Craft::$app->getSession()->setError('Could not disconnect from Commerce24.');
            return $this->redirect('site7-studio/commerce?tab=account');
        }

        $plugin->cache->invalidateTags(['commerce24']);
        Craft::$app->getSession()->setNotice('Disconnected from Commerce24.');
        return $this->redirect('site7-studio/commerce?tab=account');
    }

    // --- License ---

    public function actionActivateLicense()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageLicense');

        $key = (string)Craft::$app->getRequest()->getRequiredBodyParam('licenseKey');
        try {
            Site7Studio::getInstance()->license->activate($key);
            Craft::$app->getSession()->setNotice('License activated.');
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Could not activate license: ' . $e->getMessage());
        }
        return $this->redirect('site7-studio/commerce?tab=license');
    }

    public function actionDeactivateLicense()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageLicense');

        try {
            Site7Studio::getInstance()->license->deactivate();
            Craft::$app->getSession()->setNotice('License deactivated.');
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Could not deactivate license: ' . $e->getMessage());
        }
        return $this->redirect('site7-studio/commerce?tab=license');
    }

    public function actionRefreshLicense()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageLicense');

        Site7Studio::getInstance()->license->refresh();
        Craft::$app->getSession()->setNotice('License refreshed.');
        return $this->redirect('site7-studio/commerce?tab=license');
    }

    public function actionValidateLicense()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageLicense');

        $isValid = Site7Studio::getInstance()->license->validateLicense();
        $isValid
            ? Craft::$app->getSession()->setNotice('License is valid.')
            : Craft::$app->getSession()->setError('License is not valid.');
        return $this->redirect('site7-studio/commerce?tab=license');
    }

    public function actionTransferLicense()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageLicense');

        $newKey = (string)Craft::$app->getRequest()->getRequiredBodyParam('licenseKey');
        try {
            Site7Studio::getInstance()->license->transfer($newKey);
            Craft::$app->getSession()->setNotice('License transferred.');
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Could not transfer license: ' . $e->getMessage());
        }
        return $this->redirect('site7-studio/commerce?tab=license');
    }

    // --- Subscription ---

    public function actionUpgradePlan()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageSubscription');

        $planHandle = (string)Craft::$app->getRequest()->getRequiredBodyParam('planHandle');
        try {
            Site7Studio::getInstance()->subscription->upgrade($planHandle);
            // Applies immediately - no scheduling - so reconcile packages
            // against it right now rather than waiting for the next time
            // Overview/Packages happens to be visited.
            $planResult = Site7Studio::getInstance()->plan->refreshCurrentPlanAndSyncEntitlements();
            // flashEntitlementChanges() may already set a 'notice' flash for
            // re-enabled packages, and setNotice() only ever keeps the last
            // one set - so this has to append to (not overwrite) that message.
            $this->flashEntitlementChanges($planResult);
            $this->appendNotice('Plan upgraded.');
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Could not upgrade plan: ' . $e->getMessage());
        }
        return $this->redirect('site7-studio/commerce?tab=subscription');
    }

    public function actionDowngradePlan()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageSubscription');

        $planHandle = (string)Craft::$app->getRequest()->getRequiredBodyParam('planHandle');
        try {
            Site7Studio::getInstance()->subscription->downgrade($planHandle);
            // Applies immediately - no scheduling - so any premium package
            // the new plan doesn't cover gets disabled right now, not on
            // some future renewal date.
            $planResult = Site7Studio::getInstance()->plan->refreshCurrentPlanAndSyncEntitlements();
            $this->flashEntitlementChanges($planResult);
            $this->appendNotice('Plan downgraded.');
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Could not downgrade plan: ' . $e->getMessage());
        }
        return $this->redirect('site7-studio/commerce?tab=subscription');
    }

    public function actionRenewSubscription()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageSubscription');

        try {
            Site7Studio::getInstance()->subscription->renew();
            Craft::$app->getSession()->setNotice('Subscription renewed.');
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Could not renew subscription: ' . $e->getMessage());
        }
        return $this->redirect('site7-studio/commerce?tab=subscription');
    }

    public function actionCancelSubscription()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageSubscription');

        try {
            Site7Studio::getInstance()->subscription->cancel();
            Craft::$app->getSession()->setNotice('Subscription canceled.');
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Could not cancel subscription: ' . $e->getMessage());
        }
        return $this->redirect('site7-studio/commerce?tab=subscription');
    }

    public function actionOpenCustomerPortal()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageSubscription');

        $url = Site7Studio::getInstance()->subscription->getManageUrl();
        if (!$url) {
            Craft::$app->getSession()->setError('The customer portal is not available yet - connect Commerce24 first.');
            return $this->redirect('site7-studio/commerce?tab=account');
        }
        return $this->redirect($url);
    }

    // --- Packages / Updates ---

    public function actionInstallPackage()
    {
        $this->requirePostRequest();
        $this->requirePermission('managePackages');

        $handle = (string)Craft::$app->getRequest()->getRequiredBodyParam('handle');
        try {
            if (Site7Studio::getInstance()->commercePackages->installEntitled($handle)) {
                Craft::$app->getSession()->setNotice("'{$handle}' was installed.");
            } else {
                Craft::$app->getSession()->setError("Could not install '{$handle}'.");
            }
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError("Could not install '{$handle}': " . $e->getMessage());
        }
        return $this->redirect('site7-studio/commerce?tab=packages');
    }

    /**
     * Permanently removes a package that a plan downgrade disabled and whose
     * grace period has passed. This is the only place that ever calls
     * PackageService::deletePendingPackage() - never automatic - and it's
     * gated behind the same in-use safety check every other package
     * deletion in this plugin uses (see PackageActionController::actionDelete()).
     */
    public function actionRemovePendingPackage()
    {
        $this->requirePostRequest();
        $this->requirePermission('managePackages');

        $handle = (string)Craft::$app->getRequest()->getRequiredBodyParam('handle');

        $usage = Site7Studio::getInstance()->packageUsage->getUsage($handle);
        if (!empty($usage)) {
            Craft::$app->getSession()->setError("Cannot remove '{$handle}'. It is currently in use by " . count($usage) . ' entries.');
            return $this->redirect('site7-studio/commerce?tab=packages');
        }

        try {
            Site7Studio::getInstance()->commercePackages->deletePendingPackage($handle);
            Craft::$app->getSession()->setNotice("'{$handle}' was removed.");
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError("Could not remove '{$handle}': " . $e->getMessage());
        }
        return $this->redirect('site7-studio/commerce?tab=packages');
    }

    public function actionCheckUpdates()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageUpdates');

        try {
            Site7Studio::getInstance()->updates->checkUpdates();
            Craft::$app->getSession()->setNotice('Checked for updates.');
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Could not check for updates: ' . $e->getMessage());
        }
        return $this->redirect('site7-studio/commerce?tab=updates');
    }

    public function actionUpdateAll()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageUpdates');

        $result = Site7Studio::getInstance()->updates->updateAll();
        $this->flashUpdateResult($result);
        return $this->redirect('site7-studio/commerce?tab=updates');
    }

    public function actionUpdateSelected()
    {
        $this->requirePostRequest();
        $this->requirePermission('manageUpdates');

        $handles = (array)Craft::$app->getRequest()->getBodyParam('handles', []);
        $result = Site7Studio::getInstance()->updates->updateSelected($handles);
        $this->flashUpdateResult($result);
        return $this->redirect('site7-studio/commerce?tab=updates');
    }

    /**
     * Flashes the result of a PlanService::refreshCurrentPlanAndSyncEntitlements()
     * call - both packages just disabled (no longer covered) and packages
     * just auto-re-enabled (covered again, and this same mechanism was what
     * had disabled them - see PackageService::syncEntitlements()'s docblock).
     *
     * @param array{disabledHandles: string[], reEnabledHandles: string[]} $planResult
     */
    private function flashEntitlementChanges(array $planResult): void
    {
        if (!empty($planResult['disabledHandles'])) {
            Craft::$app->getSession()->setError(
                'Your plan no longer includes: ' . implode(', ', $planResult['disabledHandles'])
                . '. They’ve been disabled and will be removable in '
                . \site7\studio\services\commerce\PackageService::GRACE_PERIOD_DAYS
                . ' days unless you upgrade again.'
            );
        }
        if (!empty($planResult['reEnabledHandles'])) {
            Craft::$app->getSession()->setNotice(
                'Your plan now includes: ' . implode(', ', $planResult['reEnabledHandles'])
                . ' again. They’ve been automatically re-enabled.'
            );
        }
    }

    /**
     * Appends $message to any notice already set (e.g. by
     * flashEntitlementChanges()) instead of overwriting it - Craft's
     * setNotice() only ever keeps the single most recent call. Must read
     * back through getNotice() (not getFlash('notice')) since CP requests
     * store the notice under a different, structured flash key - see
     * SessionBehavior::setNotice()/getNotice().
     */
    private function appendNotice(string $message): void
    {
        $session = Craft::$app->getSession();
        $existing = $session->getNotice();
        $session->setNotice($existing ? $existing . ' ' . $message : $message);
    }

    private function flashUpdateResult(array $result): void
    {
        if (!empty($result['errors'])) {
            Craft::$app->getSession()->setError('Some updates failed: ' . implode(', ', $result['errors']));
            return;
        }
        Craft::$app->getSession()->setNotice(
            empty($result['updated']) ? 'Nothing to update.' : 'Updated: ' . implode(', ', $result['updated'])
        );
    }
}
