<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use Pollora\Hornet\Domain\Exceptions\TransportException;

/**
 * Where the known vulnerabilities of packages come from. Only names and versions are sent: never the address of the site.
 *
 * @internal
 */
interface AdvisoryDatabase
{
    /**
     * Which records concern each package at its version.
     *
     * @param  list<ComposerPackage>  $packages
     * @return list<array<string, string>> for each package, in the same order: id of the record => when it was last changed
     *
     * @throws TransportException when it did not answer for all of them
     */
    public function affecting(array $packages): array;

    /**
     * @param  string  $modified  as {@see affecting()} gave it: a record that changed is another one to keep
     *
     * @throws TransportException
     */
    public function advisory(string $id, string $modified): Advisory;
}
