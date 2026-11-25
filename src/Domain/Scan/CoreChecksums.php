<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use Pollora\Hornet\Domain\Exceptions\TransportException;

/**
 * What WordPress.org says the files of a release should look like.
 */
interface CoreChecksums
{
    /**
     * @param  string  $locale  `en_US` or the locale the core was downloaded in
     * @return array<string, string>|null the MD5 of each file by path; null when the list does not exist for this version and locale (a beta, a build of the repository)
     *
     * @throws TransportException when WordPress.org did not answer
     */
    public function checksums(string $version, string $locale): ?array;
}
