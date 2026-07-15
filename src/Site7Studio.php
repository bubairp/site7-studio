<?php

namespace site7\studio;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use site7\studio\base\PluginTrait;
use site7\studio\models\Settings;
use site7\studio\providers\CoreServiceProvider;
use site7\studio\providers\CpServiceProvider;
use site7\studio\providers\EventServiceProvider;

/**
 * Site7 Studio plugin
 *
 * @method static Site7Studio getInstance()
 * @method Settings getSettings()
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
        ];

        foreach ($providers as $provider) {
            $provider->register($this);
        }
    }

    private function attachEventHandlers(): void
    {
        // Event listeners will be registered in future sprints
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
    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate(
            'site7-studio/settings',
            [
                'settings' => $this->getSettings()
            ]
        );
    }
}
