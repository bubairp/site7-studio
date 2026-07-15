<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;

/**
 * Class ConfigService
 *
 * Provides a standardized way to retrieve Site7 Studio configuration values.
 */
class ConfigService extends Component
{
    /**
     * Retrieves a configuration setting.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $config = Craft::$app->getConfig()->getConfigFromFile('site7-studio');
        return $config[$key] ?? $default;
    }
}
