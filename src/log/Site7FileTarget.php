<?php

namespace site7\studio\log;

use craft\log\FileTarget;

/**
 * Class Site7FileTarget
 *
 * A dedicated log target for Site7 Studio.
 */
class Site7FileTarget extends FileTarget
{
    /**
     * @inheritdoc
     */
    public $logFile = '@storage/logs/site7-studio.log';
}
