<?php

namespace site7\studio\providers;

use site7\studio\Site7Studio;

/**
 * Interface ServiceProviderInterface
 *
 * Defines the contract for all Site7 Studio service providers.
 */
interface ServiceProviderInterface
{
    /**
     * Registers services into the dependency injection container.
     *
     * @param Site7Studio $plugin
     * @return void
     */
    public function register(Site7Studio $plugin): void;
}
