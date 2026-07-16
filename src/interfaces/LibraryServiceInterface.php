<?php

namespace site7\studio\interfaces;

interface LibraryServiceInterface
{
    /**
     * @return \site7\studio\models\Asset[]
     */
    public function getAllAssets(): array;

    /**
     * @return \site7\studio\models\Asset[]
     */
    public function getAssetsBySource(string $sourceHandle): array;
}
