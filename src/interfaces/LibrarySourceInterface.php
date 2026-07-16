<?php

namespace site7\studio\interfaces;

/**
 * Interface LibrarySourceInterface
 *
 * Defines the contract for an asset source in the Site7 Library.
 * This allows the library to aggregate components and templates from multiple origins
 * (e.g., Local/Built-in, Marketplace, Cloud, Organization, AI).
 */
interface LibrarySourceInterface
{
    /**
     * Returns the unique handle of the library source.
     *
     * @return string
     */
    public function getSourceHandle(): string;

    /**
     * Returns the human-readable name of the library source.
     *
     * @return string
     */
    public function getSourceName(): string;

    /**
     * Retrieves an array of Asset models representing components available from this source.
     *
     * @return \site7\studio\models\Asset[]
     */
    public function getComponents(): array;

    /**
     * Retrieves an array of Asset models representing templates available from this source.
     *
     * @return \site7\studio\models\Asset[]
     */
    public function getTemplates(): array;
}
