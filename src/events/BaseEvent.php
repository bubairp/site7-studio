<?php

namespace site7\studio\events;

use yii\base\Event;

/**
 * Class BaseEvent
 *
 * Base class for all Site7 Studio events.
 */
abstract class BaseEvent extends Event implements EventInterface
{
    /**
     * @inheritdoc
     */
    public function getEventName(): string
    {
        return static::class;
    }
}
