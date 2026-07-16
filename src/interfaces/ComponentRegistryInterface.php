<?php

namespace site7\studio\interfaces;

interface ComponentRegistryInterface
{
    /**
     * @return array
     */
    public function getComponents(): array;

    /**
     * @return void
     */
    public function loadComponents(): void;
}
