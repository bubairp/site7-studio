<?php

namespace site7\studio\events;

use Craft;
use craft\base\Component;
use yii\base\Event;

/**
 * Class EventDispatcher
 *
 * Dispatches events within the Site7 Studio ecosystem and handles subscriber registration.
 */
class EventDispatcher extends Component
{
    /**
     * Dispatches a custom Site7 Studio event.
     *
     * @param EventInterface|Event $event
     * @return void
     */
    public function dispatch(EventInterface $event): void
    {
        if ($event instanceof Event) {
            Event::trigger(get_class($event), $event->getEventName(), $event);
        }
    }

    /**
     * Registers a subscriber.
     *
     * @param EventSubscriberInterface $subscriber
     * @return void
     */
    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        foreach ($subscriber->getSubscribedEvents() as $subscription) {
            if (is_array($subscription) && count($subscription) >= 3) {
                $class = $subscription[0];
                $eventName = $subscription[1];
                $method = $subscription[2];
                
                Event::on($class, $eventName, [$subscriber, $method]);
            }
        }
    }
}
