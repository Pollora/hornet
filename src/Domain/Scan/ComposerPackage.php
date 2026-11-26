<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * A package of the project that comes from Packagist, with the release that is installed.
 *
 * @internal
 */
final readonly class ComposerPackage
{
    /**
     * @param  string  $version  without a leading `v`
     * @param  bool  $dev  required for development only
     */
    public function __construct(
        public string $name,
        public string $version,
        public bool $dev = false,
    ) {}
}
