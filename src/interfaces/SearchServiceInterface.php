<?php

namespace site7\studio\interfaces;

interface SearchServiceInterface
{
    /**
     * Searches for assets across all sources based on query and category.
     *
     * @param string $query
     * @param string $category
     * @return \site7\studio\models\Asset[]
     */
    public function search(string $query = '', string $category = ''): array;
}
