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
     * Downloads a package archive to $destination: never cached (request()
     * caches every GET, which would put whole archives in Craft's cache),
     * streamed to disk, with a long timeout - a page Template with its
     * images is tens of MB. Accepts the archive as raw bytes (application/zip)
     * or in the JSON envelope {"contentsBase64": "..."} every endpoint has
     * used so far.
     *
     * @throws CommerceApiException
     */
    public function download(string $endpoint, string $destination, int $timeout = 900): void
    {
        if (!$this->isConfigured()) {
            throw new CommerceApiException('Commerce24 is not configured yet.');
        }

        $temp = $destination . '.part';
        try {
            $response = $this->getHttpClient()->request('GET', ltrim($endpoint, '/'), [
                'sink' => $temp,
                'timeout' => $timeout,
                'headers' => ['Accept' => 'application/zip, application/json'],
            ]);
        } catch (GuzzleException $e) {
            $message = self::describe($e, 'Could not download from Commerce24');
            @unlink($temp);
            throw new CommerceApiException($message, 0, $e);
        }

        if (str_contains(strtolower($response->getHeaderLine('Content-Type')), 'json')) {
            $data = json_decode((string)file_get_contents($temp), true);
            @unlink($temp);
            if (empty($data['contentsBase64'])) {
                throw new CommerceApiException('Commerce24 did not return archive contents' . (isset($data['error']) ? ": {$data['error']}" : '.'));
            }
            file_put_contents($destination, base64_decode($data['contentsBase64']));
            return;
        }

        rename($temp, $destination);
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
            throw new CommerceApiException(self::describe($e, 'Could not reach Commerce24'), 0, $e);
        }
    }

    /**
     * A user-facing message for a failed request: when Commerce24 answered
     * with an error, its own {"error": ...} text and the HTTP status (it was
     * reached, it said no); otherwise $unreachable plus the transport error.
     */
    private static function describe(GuzzleException $e, string $unreachable): string
    {
        if ($e instanceof \GuzzleHttp\Exception\RequestException && ($response = $e->getResponse())) {
            $body = $response->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $data = json_decode((string)$body->getContents(), true);
            $reason = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : $response->getReasonPhrase();

            return "Commerce24: {$reason} (HTTP {$response->getStatusCode()})";
        }

        return "{$unreachable}: {$e->getMessage()}";
    }

    private function getHttpClient(): Client
    {
        if ($this->httpClient === null) {
            $settings = Site7Studio::getInstance()->getSettings();

            $this->httpClient = new Client([
                'base_uri' => rtrim((string)$settings->commerceApiEndpoint, '/') . '/',
                'timeout' => max(1, (int)$settings->commerceTimeout),
                'headers' => array_filter([
                    'Authorization' => 'Bearer ' . $this->resolveApiKey(),
                    'X-Site7-Environment' => $settings->commerceEnvironment ?: 'production',
                    'X-Site7-Store' => $settings->commerceStoreIdentifier ?: null,
                    'Accept' => 'application/json',
                ]),
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
