<?php

namespace site7\studio\services\commerce;

use Craft;
use craft\base\Component;
use site7\studio\events\commerce\AfterLicenseValidationEvent;
use site7\studio\events\commerce\BeforeLicenseValidationEvent;
use site7\studio\events\commerce\LicenseActivatedEvent;
use site7\studio\events\commerce\LicenseDeactivatedEvent;
use site7\studio\interfaces\CommerceClientInterface;
use site7\studio\interfaces\LicenseProviderInterface;
use site7\studio\models\commerce\CommerceApiException;
use site7\studio\models\commerce\LicenseInfo;
use site7\studio\Site7Studio;

/**
 * License management, backed by Commerce24. See LicenseProviderInterface's
 * docblock - CommerceController depends on that interface, not this class.
 */
class LicenseService extends Component implements LicenseProviderInterface
{
    private const CACHE_KEY = 'site7-studio.commerce24.license';

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
    public function getLicense(): LicenseInfo
    {
        if (!$this->client->isConfigured()) {
            return new LicenseInfo(['status' => 'unlicensed']);
        }

        try {
            $data = Site7Studio::getInstance()->cache->getOrSet(
                self::CACHE_KEY,
                fn() => $this->client->request('GET', '/license'),
                (int)Site7Studio::getInstance()->getSettings()->commerceCacheDuration,
                ['commerce24', 'commerce24-license']
            );
            return new LicenseInfo($data);
        } catch (CommerceApiException $e) {
            Craft::warning('Could not fetch license from Commerce24: ' . $e->getMessage(), 'site7-studio');
            return new LicenseInfo(['status' => 'unknown']);
        }
    }

    /**
     * @inheritdoc
     */
    public function activate(string $licenseKey): LicenseInfo
    {
        $data = $this->client->request('POST', '/license/activate', ['json' => ['key' => $licenseKey]]);
        $license = new LicenseInfo($data);

        Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new LicenseActivatedEvent(['license' => $license]));

        return $license;
    }

    /**
     * @inheritdoc
     */
    public function deactivate(): bool
    {
        $current = $this->getLicense();
        $this->client->request('POST', '/license/deactivate');

        Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new LicenseDeactivatedEvent(['licenseKey' => $current->key]));

        return true;
    }

    /**
     * @inheritdoc
     */
    public function refresh(): LicenseInfo
    {
        Craft::$app->getCache()->delete(self::CACHE_KEY);
        return $this->getLicense();
    }

    /**
     * @inheritdoc
     */
    public function validateLicense(): bool
    {
        $dispatcher = Site7Studio::getInstance()->getService('eventDispatcher');

        $before = new BeforeLicenseValidationEvent();
        $dispatcher->dispatch($before);
        if ($before->handled) {
            return $before->isValid;
        }

        $isValid = $this->getLicense()->isActive();

        $dispatcher->dispatch(new AfterLicenseValidationEvent(['isValid' => $isValid]));

        return $isValid;
    }

    /**
     * @inheritdoc
     */
    public function transfer(string $newLicenseKey): LicenseInfo
    {
        $data = $this->client->request('POST', '/license/transfer', ['json' => ['key' => $newLicenseKey]]);
        Craft::$app->getCache()->delete(self::CACHE_KEY);
        return new LicenseInfo($data);
    }
}
