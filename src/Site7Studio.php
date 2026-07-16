<?php

namespace site7\studio;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use site7\studio\base\PluginTrait;
use site7\studio\models\Settings;
use site7\studio\providers\CoreServiceProvider;
use site7\studio\providers\CpServiceProvider;
use site7\studio\providers\EventServiceProvider;
use site7\studio\providers\LibraryServiceProvider;

/**
 * Site7 Studio plugin
 *
 * @method static Site7Studio getInstance()
 * @method Settings getSettings()
 * @property-read \site7\studio\services\PackageManagerService $packageManager
 * @property-read \site7\studio\services\CraftResourceService $craftResourceGenerator
 * @property-read \site7\studio\services\PackageUsageService $packageUsage
 */
class Site7Studio extends Plugin
{
    use PluginTrait;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function __construct($id, $parent = null, $config = [])
    {
        parent::__construct($id, $parent, $config);
        $this->registerServiceProviders();
    }

    public function init(): void
    {
        parent::init();

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function() {
            $this->attachEventHandlers();
        });
    }

    /**
     * Registers all Service Providers for the plugin infrastructure.
     */
    private function registerServiceProviders(): void
    {
        $providers = [
            new CoreServiceProvider(),
            new EventServiceProvider(),
            new CpServiceProvider(),
            new LibraryServiceProvider(),
        ];

        foreach ($providers as $provider) {
            $provider->register($this);
        }
    }

    private function attachEventHandlers(): void
    {
        // Event listeners will be registered in future sprints
        
        // Register CP routes to point to our controllers instead of rendering templates directly
        \yii\base\Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['site7-studio'] = 'site7-studio/default/index';
                $event->rules['site7-studio/setup'] = 'site7-studio/setup/index';
                $event->rules['site7-studio/setup/complete'] = 'site7-studio/setup/complete';
                $event->rules['site7-studio/library'] = 'site7-studio/library/index';
                $event->rules['site7-studio/library/package/<handle:[\w\-]+>'] = 'site7-studio/library/package';
                $event->rules['site7-studio/library/package/<handle:[\w\-]+>/preview'] = 'site7-studio/library/preview';
                $event->rules['site7-studio/library/package/<handle:[\w\-]+>/preview-image'] = 'site7-studio/library/preview-image';
            }
        );
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $items = $this->getNavigation()->getNavItems();
        return $items[0] ?? parent::getCpNavItem();
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            \craft\helpers\UrlHelper::cpUrl('site7-studio/settings')
        );
    }
}
