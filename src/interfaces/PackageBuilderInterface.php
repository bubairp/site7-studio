<?php

namespace site7\studio\interfaces;

/**
 * Builds a distributable .s7pkg for a package. Deliberately does not
 * duplicate PackageExportService's zip/checksum/dependency-closure logic -
 * PackageBuilderService (this interface's implementation) delegates to it
 * directly, and only adds generating the extra files a *publish* build
 * wants alongside the ones an *export* build already produces
 * (package.json, CHANGELOG.md, LICENSE.md - see PackageBuilderService).
 */
interface PackageBuilderInterface
{
    /**
     * Builds a .s7pkg for $handle and returns its absolute path.
     *
     * @param array $options {includeDependencies?: bool}
     * @throws \Exception if the package can't be found or built.
     */
    public function build(string $handle, array $options = []): string;
}
