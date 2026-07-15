<?php

namespace site7\studio\services;

use craft\base\Component;
use site7\studio\Site7Studio;
use site7\studio\events\RegisterNavigationEvent;

/**
 * Class CpNavigationRegistry
 *
 * Collects and provides CP navigation items for Site7 Studio.
 */
class CpNavigationRegistry extends Component
{
    private array $navItems = [];
    private bool $isCompiled = false;

    /**
     * Registers a navigation item to the registry. Prevents duplicates based on URL.
     *
     * @param array $item
     * @return void
     */
    public function registerNavItem(array $item): void
    {
        $url = $item['url'] ?? null;
        
        if ($url !== null) {
            foreach ($this->navItems as $existing) {
                if (isset($existing['url']) && $existing['url'] === $url) {
                    return; // Prevent duplicate
                }
            }
        }
        
        $this->navItems[] = $item;
    }

    /**
     * Retrieves all registered navigation items, triggering the registration event
     * if they haven't been compiled yet.
     *
     * @return array
     */
    public function getNavItems(): array
    {
        if (!$this->isCompiled) {
            $event = new RegisterNavigationEvent();
            $event->registry = $this;

            Site7Studio::getInstance()->getService('eventDispatcher')->dispatch($event);

            $this->isCompiled = true;
        }

        return $this->navItems;
    }
}
