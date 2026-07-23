<?php

namespace site7\studio\interfaces;

use site7\studio\models\commerce\CommerceApiException;

/**
 * The single, exclusive gateway to Commerce24's REST API. No controller or
 * template may call Commerce24 directly, or construct a CommerceClient's
 * HTTP layer itself - every business service (LicenseService,
 * SubscriptionService, PlanService, commerce PackageService, DownloadService,
 * UpdateService) is handed one of these and never touches HTTP itself, so a
 * future transport swap (a different SDK, a queued/offline mode, a mock for
 * tests) only ever requires a new implementation of this interface.
 */
interface CommerceClientInterface
{
    /**
     * Whether this client has enough configuration (endpoint + API key) to
     * attempt a request at all. Callers should check this before calling
     * request() to distinguish "not configured" from "configured but the
     * request failed."
     */
    public function isConfigured(): bool;

    /**
     * Authenticates with Commerce24, if the underlying transport requires an
     * explicit step before making requests (e.g. exchanging an API key for a
     * short-lived token). A no-op for transports that authenticate per-request.
     */
    public function authenticate(): void;

    /**
     * Forces a refresh of whatever the transport uses to authenticate
     * (e.g. an expired token) and retries once, transparently, from request().
     */
    public function refreshToken(): void;

    /**
     * Calls a Commerce24 API endpoint and returns the decoded JSON body.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, ...).
     * @param string $endpoint Path relative to the configured API endpoint (e.g. '/license').
     * @param array $options Guzzle-style request options (json, query, headers, ...).
     * @throws CommerceApiException on any transport error, non-2xx response, or if not configured.
     */
    public function request(string $method, string $endpoint, array $options = []): array;
}
