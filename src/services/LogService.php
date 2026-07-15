<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use site7\studio\log\Site7FileTarget;

/**
 * Class LogService
 *
 * Provides dedicated logging capabilities for Site7 Studio.
 */
class LogService extends Component
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        
        $dispatcher = Craft::$getLogger();
        $target = new Site7FileTarget([
            'categories' => ['site7\studio\*'],
            'levels' => ['error', 'warning', 'info', 'trace', 'profile'],
            'logVars' => [],
        ]);
        
        $dispatcher->targets['site7-studio'] = $target;
    }

    /**
     * Log an error message.
     */
    public function error(string $message, string $category = 'site7\studio\LogService'): void
    {
        Craft::error($message, $category);
    }

    /**
     * Log an info message.
     */
    public function info(string $message, string $category = 'site7\studio\LogService'): void
    {
        Craft::info($message, $category);
    }
}
