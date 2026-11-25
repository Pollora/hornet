<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use Pollora\Hornet\Domain\Exceptions\TransportException;

/**
 * What WordPress.org says the files of a release of a plugin should look like.
 */
interface PluginChecksums
{
    /**
     * @return array<string, list<string>>|null the SHA-256 that a file may have, by path inside the plugin (a file can have several: a readme that changes);
     *                                          null when WordPress.org has no list for this slug and version (a premium plugin, a plugin installed from elsewhere, a version it never had)
     *
     * @throws TransportException when WordPress.org did not answer
     */
    public function checksums(string $slug, string $version): ?array;
}
