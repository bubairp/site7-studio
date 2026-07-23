<?php

namespace site7\studio\services\commerce;

use Craft;
use craft\base\Component;
use site7\studio\events\commerce\PlanChangedEvent;
use site7\studio\interfaces\CommerceClientInterface;
use site7\studio\models\commerce\CommerceApiException;
use site7\studio\models\commerce\PlanInfo;
use site7\studio\Site7Studio;

/**
 * Reads plan definitions and the current plan from Commerce24. There is no
 * fixed list of plans anywhere in this codebase - Starter/Professional/
 * Business/Enterprise are Commerce24's plans today, but PlanService and
 * FeatureGateService never hardcode those names; they only read whatever
 * PlanInfo Commerce24 currently returns. See FeatureGateInterface.
 */
class PlanService extends Component
{
    private const CACHE_KEY = 'site7-studio.commerce24.current-plan';
    private const CACHE_KEY_ALL = 'site7-studio.commerce24.all-plans';

    /**
     * The plan handle Site7 Studio last reconciled installed packages
     * against - stored with no expiry, independent of CACHE_KEY's short TTL.
     * A scheduled downgrade can flip Commerce24's own "current plan" between
     * two unrelated requests (whenever either happens to land after the
     * renewal date), so comparing "before" and "after" within a single
     * request is not reliable - only a durable record of what was last acted
     * on can catch that the plan actually changed since last time anyone looked.
     */
    private const LAST_RECONCILED_PLAN_CACHE_KEY = 'site7-studio.commerce24.last-reconciled-plan';

    public CommerceClientInterface $client;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        if (!isset($this->client)) {
            $this->client = Site7Studio::getInstance()->commerceClient;
        }
    }

    /**
     * Every plan Commerce24 currently offers (for a Plans/upgrade comparison screen).
     *
     * @return PlanInfo[]
     */
    public function getAllPlans(): array
    {
        if (!$this->client->isConfigured()) {
            return [];
        }

        try {
            $data = Site7Studio::getInstance()->cache->getOrSet(
                self::CACHE_KEY_ALL,
                fn() => $this->client->request('GET', '/plans'),
                (int)Site7Studio::getInstance()->getSettings()->commerceCacheDuration,
                ['commerce24', 'commerce24-plans']
            );
            return array_map(fn(array $plan) => new PlanInfo($plan), $data['plans'] ?? []);
        } catch (CommerceApiException $e) {
            Craft::warning('Could not fetch plans from Commerce24: ' . $e->getMessage(), 'site7-studio');
            return [];
        }
    }

    /**
     * The plan the current subscription is on, or null if it can't be resolved
     * (Commerce24 unreachable, or no subscription at all).
     */
    public function getCurrentPlan(): ?PlanInfo
    {
        if (!$this->client->isConfigured()) {
            return null;
        }

        try {
            $data = Site7Studio::getInstance()->cache->getOrSet(
                self::CACHE_KEY,
                fn() => $this->client->request('GET', '/plans/current'),
                (int)Site7Studio::getInstance()->getSettings()->commerceCacheDuration,
                ['commerce24', 'commerce24-plans']
            );
            return empty($data) ? null : new PlanInfo($data);
        } catch (CommerceApiException $e) {
            Craft::warning('Could not fetch the current plan from Commerce24: ' . $e->getMessage(), 'site7-studio');
            return null;
        }
    }

    /**
     * Clears the cached current plan and, if it actually changed, dispatches PlanChangedEvent.
     */
    public function refreshCurrentPlan(): ?PlanInfo
    {
        $previous = $this->getCurrentPlan();
        Craft::$app->getCache()->delete(self::CACHE_KEY);
        $current = $this->getCurrentPlan();

        if ($previous?->handle !== $current?->handle) {
            Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new PlanChangedEvent([
                'previousPlan' => $previous,
                'newPlan' => $current,
            ]));
        }

        return $current;
    }

    /**
     * Refreshes the current plan and, if it differs from the plan Site7
     * Studio last reconciled packages against, reconciles them now (see
     * PackageService::syncEntitlements()) so a downgrade doesn't silently
     * leave premium packages enabled. CommerceController calls this (rather
     * than plain refreshCurrentPlan()) from wherever a plan change should
     * visibly take effect, and flashes $disabledHandles to the user.
     *
     * @return array{plan: ?PlanInfo, changed: bool, disabledHandles: string[]}
     */
    public function refreshCurrentPlanAndSyncEntitlements(): array
    {
        $current = $this->refreshCurrentPlan();
        $currentHandle = $current?->handle;
        $lastReconciled = Craft::$app->getCache()->get(self::LAST_RECONCILED_PLAN_CACHE_KEY) ?: null;
        $changed = $lastReconciled !== $currentHandle;

        $disabledHandles = [];
        if ($changed) {
            Craft::$app->getCache()->set(self::LAST_RECONCILED_PLAN_CACHE_KEY, $currentHandle, 0);
            if ($current !== null) {
                $disabledHandles = (new PackageService())->syncEntitlements($current);
            }
        }

        return ['plan' => $current, 'changed' => $changed, 'disabledHandles' => $disabledHandles];
    }
}
