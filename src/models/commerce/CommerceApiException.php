<?php

namespace site7\studio\models\commerce;

/**
 * Thrown by CommerceClient for any failure talking to Commerce24 - a
 * non-2xx response, a network/timeout error, or a plugin that isn't
 * configured with an API endpoint/key yet. Callers (LicenseService,
 * SubscriptionService, etc.) catch this specifically so a Commerce24
 * outage degrades the CP to "last known / not connected" state instead of
 * a fatal error.
 */
class CommerceApiException extends \Exception
{
}
