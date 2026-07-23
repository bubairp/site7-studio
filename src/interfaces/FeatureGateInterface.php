<?php

namespace site7\studio\interfaces;

use site7\studio\models\commerce\PlanInfo;

/**
 * The single authority on "is this feature available." Controllers,
 * services, and templates must never check a plan name or license status
 * directly (`if (plan == 'business')`) - they call
 * `Site7Studio::getInstance()->featureGate->allows('teamManagement')`
 * instead. This is what lets Commerce24 add or redefine plans/features at
 * any time without a code change on the SITE7 Studio side: this service
 * only ever reads the *current* plan's feature list, never a hardcoded map.
 */
interface FeatureGateInterface
{
    /** Whether the current plan/license grants $feature. Fails closed (false) if no plan can be resolved. */
    public function allows(string $feature): bool;

    /** Every feature handle the current plan grants. */
    public function getAllowedFeatures(): array;

    /** The plan this gate is currently evaluating against, if one could be resolved. */
    public function getPlan(): ?PlanInfo;
}
