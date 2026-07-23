<?php

namespace site7\studio\interfaces;

use site7\studio\models\commerce\SubscriptionInfo;

/**
 * Contract for subscription/billing state, backed by Commerce24. All actual
 * billing (charging cards, prorations, invoices) happens on Commerce24's
 * side - this only reflects and requests changes to that state.
 */
interface SubscriptionProviderInterface
{
    /** Returns the current (possibly cached) subscription state. */
    public function getSubscription(): SubscriptionInfo;

    /** Requests an upgrade to a higher plan. Returns the resulting subscription state. */
    public function upgrade(string $planHandle): SubscriptionInfo;

    /** Requests a downgrade to a lower plan. Returns the resulting subscription state. */
    public function downgrade(string $planHandle): SubscriptionInfo;

    /** Requests an immediate renewal of the current billing cycle. */
    public function renew(): SubscriptionInfo;

    /** Cancels the subscription (takes effect per Commerce24's own cancellation policy). */
    public function cancel(): SubscriptionInfo;

    /** A URL to Commerce24's own subscription management/customer portal page. */
    public function getManageUrl(): ?string;
}
