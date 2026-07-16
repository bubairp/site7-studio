<?php

namespace site7\studio\interfaces;

interface CategoryRegistryInterface
{
    /**
     * @return array
     */
    public function getCategories(): array;

    /**
     * @return void
     */
    public function loadCategories(): void;
}
