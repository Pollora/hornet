<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use Pollora\Hornet\Domain\Exceptions\TransportException;

/**
 * The WordPress.org plugin directory. Only a folder name is asked about: never the version, never the address of the site.
 *
 * @internal
 */
interface PluginDirectory
{
    /**
     * @throws TransportException when it did not answer, or answered something that cannot be read
     */
    public function of(string $slug): PluginListing;
}
