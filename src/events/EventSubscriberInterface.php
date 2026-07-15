<?php

namespace site7\studio\events;

/**
 * Interface EventSubscriberInterface
 *
 * Contract for classes that subscribe to events.
 */
interface EventSubscriberInterface
{
    /**
     * Returns an array of events this subscriber wants to listen to.
     *
     * @return array
     */
    public function getSubscribedEvents(): array;
}
