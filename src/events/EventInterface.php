<?php

namespace site7\studio\events;

/**
 * Interface EventInterface
 *
 * Defines the contract for all custom Site7 Studio events.
 */
interface EventInterface
{
    /**
     * Gets the unique event name.
     *
     * @return string
     */
    public function getEventName(): string;
}
