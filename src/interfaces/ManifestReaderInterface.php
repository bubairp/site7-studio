<?php

namespace site7\studio\interfaces;

interface ManifestReaderInterface
{
    /**
     * Reads and decodes a manifest.json file from a given directory path.
     *
     * @param string $directoryPath
     * @return array|null The decoded manifest data, or null on failure.
     */
    public function read(string $directoryPath): ?array;
}
