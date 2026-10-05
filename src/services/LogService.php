<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\log\MonologTarget;
use Psr\Log\LogLevel;

/**
 * Class LogService
 *
 * Provides dedicated logging capabilities for Site7 Studio: everything the
 * plugin logs (category "site7-studio" or a site7\studio\ class) also goes
 * to storage/logs/site7-studio-<date>.log, besides Craft's own logs.
 * Started from Site7Studio::init().
 */
class LogService extends Component
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        Craft::getLogger()->dispatcher->targets['site7-studio'] = new MonologTarget([
            'name' => 'site7-studio',
            'categories' => ['site7-studio', 'site7\studio\*'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => true,
        ]);
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
