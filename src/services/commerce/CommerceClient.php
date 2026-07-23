<?php

namespace site7\studio\services\commerce;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use site7\studio\interfaces\CommerceClientInterface;
use site7\studio\models\commerce\CommerceApiException;
use site7\studio\Site7Studio;

/**
 * The sole HTTP gateway to Commerce24. Every business service in
 * site7\studio\services\commerce\ is handed this (by interface) instead of
 * touching Guzzle/HTTP itself - see CommerceClientInterface's docblock for why.
 *
 * Configuration (endpoint, API key, environment, timeout) comes from the
 * plugin's own Settings model (the Commerce tab on the Settings CP page),
 * not a static config array, since it's meant to be set per-environment by
 * whoever installs the plugin. The API key supports Craft's standard
 * `$ENV_VAR_NAME` env-var syntax via App::parseEnv() so it never has to be
 * stored in the database/project config in plaintext.
 */
class CommerceClient extends Component implements CommerceClientInterface
{
    private ?Client $httpClient = null;
    private ?string $authToken = null;

    /**
     * @inheritdoc
     */
    public function isConfigured(): bool
    {
        $settings = Site7Studio::getInstance()->getSettings();
        return !empty($settings->commerceApiEndpoint) && $this->resolveApiKey() !== '';
    }

    /**
     * @inheritdoc
     */
    public function authenticate(): void
    {
        // Commerce24's API is authenticated per-request via an Authorization
        // header (see getHttpClient()) rather than a separate login step, so
        // there's nothing to do upfront - this exists so a future token-
        // exchange-based auth flow can be added here without changing
        // CommerceClientInterface or any caller.
    }

    /**
     * @inheritdoc
     */
    public function refreshToken(): void
    {
        $this->authToken = null;
        $this->httpClient = null;
    }

    /**
     * @inheritdoc
     */
    public function request(string $method, string $endpoint, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new CommerceApiException('Commerce24 is not configured yet. Set an API Endpoint and API Key on the Commerce tab of Site7 Studio Settings.');
        }

        $settings = Site7Studio::getInstance()->getSettings();
        $cacheKey = 'site7-studio.commerce24.' . md5($method . '|' . $endpoint . '|' . json_encode($options['query'] ?? []));

        if (strtoupper($method) === 'GET') {
            return Site7Studio::getInstance()->cache->getOrSet(
                $cacheKey,
                fn() => $this->send($method, $endpoint, $options),
                max(1, (int)$settings->commerceCacheDuration),
                ['commerce24']
            );
        }

        // Mutating requests are never cached, and invalidate any cached GETs
        // so the very next read reflects the change (e.g. right after activating a license).
        $result = $this->send($method, $endpoint, $options);
        Site7Studio::getInstance()->cache->invalidateTags(['commerce24']);
        return $result;
    }

    /**
     * @throws CommerceApiException
     */
    private function send(string $method, string $endpoint, array $options): array
    {
        try {
            $response = $this->getHttpClient()->request($method, ltrim($endpoint, '/'), $options);
            $body = (string)$response->getBody();
            $decoded = $body === '' ? [] : json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new CommerceApiException("Commerce24 returned a non-JSON response from {$endpoint}.");
            }

            return is_array($decoded) ? $decoded : [];
        } catch (GuzzleException $e) {
            Craft::warning("Commerce24 request failed ({$method} {$endpoint}): " . $e->getMessage(), 'site7-studio');
            throw new CommerceApiException('Could not reach Commerce24: ' . $e->getMessage(), 0, $e);
        }
    }

    private function getHttpClient(): Client
    {
        if ($this->httpClient === null) {
            $settings = Site7Studio::getInstance()->getSettings();

            $this->httpClient = new Client([
                'base_uri' => rtrim((string)$settings->commerceApiEndpoint, '/') . '/',
                'timeout' => max(1, (int)$settings->commerceTimeout),
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->resolveApiKey(),
                    'X-Site7-Environment' => $settings->commerceEnvironment ?: 'production',
                    'Accept' => 'application/json',
                ],
            ]);
        }

        return $this->httpClient;
    }

    private function resolveApiKey(): string
    {
        $settings = Site7Studio::getInstance()->getSettings();
        return (string)App::parseEnv($settings->commerceApiKey ?? '');
    }
}
