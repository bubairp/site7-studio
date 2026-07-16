<?php

namespace site7\studio\interfaces;

interface TemplateRegistryInterface
{
    /**
     * @return array
     */
    public function getTemplates(): array;

    /**
     * @return void
     */
    public function loadTemplates(): void;
}
