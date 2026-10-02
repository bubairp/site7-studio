<?php
namespace modules\theme;

use Craft;
use yii\base\Module as BaseModule;

class Module extends BaseModule
{
    public function init()
    {
        parent::init();
        Craft::setAlias('@theme', __DIR__);
        Craft::info('Theme module loaded', __METHOD__);

        // Register controller namespace
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'modules\\theme\\console\\controllers';
        } else {
            $this->controllerNamespace = 'modules\\theme\\controllers';
        }

        // Register site routes
        Craft::$app->getUrlManager()->addRules([
            'styles.css' => 'theme/css/index',
        ], false);

        // Clear dynamic CSS cache when an entry is saved
        $events = [
            \craft\services\Elements::EVENT_AFTER_SAVE_ELEMENT,
            \craft\services\Elements::EVENT_AFTER_DELETE_ELEMENT,
        ];

        foreach ($events as $event) {
            \yii\base\Event::on(
                \craft\services\Elements::class,
                $event,
                function (\craft\events\ElementEvent $event) {
                    if ($event->element instanceof \craft\elements\Entry) {
                        Craft::$app->getCache()->delete('dynamic-css-output');
                        Craft::info('Dynamic CSS cache cleared due to entry save/delete.', __METHOD__);
                    }
                }
            );
        }
    }
}
