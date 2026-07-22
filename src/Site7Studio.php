<?php

namespace site7\studio;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineAltActionsEvent;
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

    public string $schemaVersion = '1.0.2';
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

        Craft::setAlias('@packages', dirname($this->getBasePath()) . '/packages');

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function() {
            $this->attachEventHandlers();
        });
    }

    /**
     * Gates Package Authoring (the New Package wizard and Package Editor -
     * create/edit/delete for Sections, Patterns, Templates, and Starter
     * Kits) to Craft's own Dev Mode. "Save as Template" and managing a
     * Template you personally captured that way stay available regardless
     * - see PackageAuthoringController and PackageActionController::actionDelete().
     */
    public static function isDevMode(): bool
    {
        return (bool)Craft::$app->getConfig()->getGeneral()->devMode;
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
        
        // Inject Pattern insertion JS into the CP
        if (Craft::$app->getRequest()->getIsCpRequest() && !Craft::$app->getRequest()->getIsConsoleRequest()) {
            \yii\base\Event::on(
                \craft\web\View::class,
                \craft\web\View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
                function (\craft\events\TemplateEvent $event) {
                    $settings = \site7\studio\Site7Studio::getInstance()->getSettings();
                    $matrixHandle = '';
                    if ($settings->matrixFieldId) {
                        $matrixField = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
                        if ($matrixField) {
                            $matrixHandle = $matrixField->handle;
                        }
                    }

                    Craft::$app->getView()->registerJs(
                        'window.site7Studio = ' . json_encode([
                            'matrixFieldHandle' => $matrixHandle
                        ]) . ';',
                        \craft\web\View::POS_HEAD
                    );
                    Craft::$app->getView()->registerAssetBundle(\site7\studio\assetbundles\PatternMatrixBundle::class);
                }
            );
        }

        // Add "Save as Template" to the entry edit screen's existing Save dropdown.
        // Uses no custom 'action' key, so it reuses the default save action exactly
        // like Craft's own "Save and continue editing" item - the entry is persisted
        // normally, then the redirect (with a marker query param) brings the editor
        // back to this same page, where template-wizard.js opens the wizard modal.
        \yii\base\Event::on(
            Entry::class,
            Entry::EVENT_DEFINE_ALT_ACTIONS,
            function (DefineAltActionsEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;
                if (!$entry->id) {
                    return;
                }

                $settings = Site7Studio::getInstance()->getSettings();
                if (!$settings->matrixFieldId) {
                    return;
                }
                $matrixField = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
                if (!$matrixField || !$entry->getFieldLayout()?->getFieldByHandle($matrixField->handle)) {
                    return;
                }

                // Blocks inserted via this plugin's own Section/Pattern/Template insert
                // flow can remain provisional drafts on the owner until a subsequent
                // native full-page save merges them, so the default (canonical-only)
                // field-value query can under-report; check inclusively of drafts here.
                $fv = $entry->getFieldValue($matrixField->handle);
                if (!$fv || $fv->status(null)->drafts(null)->savedDraftsOnly(false)->count() === 0) {
                    return;
                }

                $editUrl = $entry->getCpEditUrl();
                if (!$editUrl) {
                    return;
                }
                $separator = str_contains($editUrl, '?') ? '&' : '?';

                // The CP edit screen normally hands us a provisional draft here, whose
                // own element id differs from the real (canonical) entry's id. The
                // "Save" that runs before this redirect merges that draft into the
                // canonical entry, so a draft id baked into the redirect URL can point
                // to an element that's gone by the time the page reloads - the
                // intermittent "Entry not found" error. Always use the canonical id.
                $canonicalId = $entry->getCanonicalId() ?? $entry->id;

                $event->altActions[] = [
                    'label' => Craft::t('site7-studio', 'Save as Template'),
                    'redirect' => $editUrl . $separator . 'site7SaveAsTemplate=1&site7EntryId=' . $canonicalId,
                    'eventData' => ['autosave' => false],
                ];
            }
        );

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
                $event->rules['site7-studio/library/package/<handle:[\w\-]+>/render-preview'] = 'site7-studio/library/render-preview';
                $event->rules['site7-studio/library/package/<handle:[\w\-]+>/preview-image'] = 'site7-studio/library/preview-image';
                $event->rules['site7-studio/packages/new'] = 'site7-studio/package-authoring/new';
                $event->rules['site7-studio/packages/<handle:[\w\-]+>/edit'] = 'site7-studio/package-authoring/edit';
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
