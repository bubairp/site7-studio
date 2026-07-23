<?php

namespace site7\studio\services\commerce;

use craft\base\Component;
use site7\studio\interfaces\FeatureGateInterface;
use site7\studio\models\commerce\PlanInfo;
use site7\studio\Site7Studio;

/**
 * The single authority on feature availability - see FeatureGateInterface's
 * docblock. Fails closed: with Commerce24 unconfigured/unreachable, or no
 * resolvable plan, allows() returns false for everything except the plan
 * a fresh/disconnected install should still function under, defined by the
 * `commerceDefaultPackage`-adjacent Settings::$commerceOfflineFeatures escape
 * hatch below (empty by default - deliberately conservative).
 *
 * Deliberately has no static `FeatureGate::allows(...)` facade: this
 * codebase's own convention (packageManager, packageUsage, marketplace, ...)
 * is always an instance accessed through the plugin's service locator, so
 * this is used the same way: `Site7Studio::getInstance()->featureGate->allows(...)`.
 */
class FeatureGateService extends Component implements FeatureGateInterface
{
    public PlanService $planService;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        if (!isset($this->planService)) {
            $this->planService = new PlanService();
        }
    }

    /**
     * @inheritdoc
     */
    public function allows(string $feature): bool
    {
        $plan = $this->getPlan();
        if ($plan !== null) {
            return $plan->grants($feature);
        }

        // No plan could be resolved (not configured, or Commerce24
        // unreachable) - fall back to whichever features an operator has
        // explicitly opted to keep working offline, via Settings. Empty by
        // default, i.e. fails closed.
        $offlineFeatures = Site7Studio::getInstance()->getSettings()->commerceOfflineFeatures;
        return in_array($feature, is_array($offlineFeatures) ? $offlineFeatures : [], true);
    }

    /**
     * @inheritdoc
     */
    public function getAllowedFeatures(): array
    {
        return $this->getPlan()?->features ?? [];
    }

    /**
     * @inheritdoc
     */
    public function getPlan(): ?PlanInfo
    {
        return $this->planService->getCurrentPlan();
    }
}
