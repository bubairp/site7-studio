<?php

namespace site7\studio\services\commerce;

use Craft;
use craft\base\Component;
use site7\studio\events\commerce\SubscriptionChangedEvent;
use site7\studio\interfaces\CommerceClientInterface;
use site7\studio\interfaces\SubscriptionProviderInterface;
use site7\studio\models\commerce\CommerceApiException;
use site7\studio\models\commerce\SubscriptionInfo;
use site7\studio\Site7Studio;

class SubscriptionService extends Component implements SubscriptionProviderInterface
{
    private const CACHE_KEY = 'site7-studio.commerce24.subscription';

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
     * @inheritdoc
     */
    public function getSubscription(): SubscriptionInfo
    {
        if (!$this->client->isConfigured()) {
            return new SubscriptionInfo(['status' => 'none']);
        }

        try {
            $data = Site7Studio::getInstance()->cache->getOrSet(
                self::CACHE_KEY,
                fn() => $this->client->request('GET', '/subscription'),
                (int)Site7Studio::getInstance()->getSettings()->commerceCacheDuration,
                ['commerce24', 'commerce24-subscription']
            );
            return new SubscriptionInfo($data);
        } catch (CommerceApiException $e) {
            Craft::warning('Could not fetch subscription from Commerce24: ' . $e->getMessage(), 'site7-studio');
            return new SubscriptionInfo(['status' => 'unknown']);
        }
    }

    /**
     * @inheritdoc
     */
    public function upgrade(string $planHandle): SubscriptionInfo
    {
        return $this->changeAndDispatch('PUT', '/subscription/upgrade', ['planHandle' => $planHandle]);
    }

    /**
     * @inheritdoc
     */
    public function downgrade(string $planHandle): SubscriptionInfo
    {
        return $this->changeAndDispatch('PUT', '/subscription/downgrade', ['planHandle' => $planHandle]);
    }

    /**
     * @inheritdoc
     */
    public function renew(): SubscriptionInfo
    {
        return $this->changeAndDispatch('POST', '/subscription/renew', []);
    }

    /**
     * @inheritdoc
     */
    public function cancel(): SubscriptionInfo
    {
        return $this->changeAndDispatch('POST', '/subscription/cancel', []);
    }

    /**
     * @inheritdoc
     */
    public function getManageUrl(): ?string
    {
        return $this->getSubscription()->manageUrl;
    }

    private function changeAndDispatch(string $method, string $endpoint, array $payload): SubscriptionInfo
    {
        $previousStatus = $this->getSubscription()->status;

        $data = $this->client->request($method, $endpoint, $payload ? ['json' => $payload] : []);
        Craft::$app->getCache()->delete(self::CACHE_KEY);
        $subscription = new SubscriptionInfo($data);

        Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new SubscriptionChangedEvent([
            'subscription' => $subscription,
            'previousStatus' => $previousStatus,
        ]));

        return $subscription;
    }
}
