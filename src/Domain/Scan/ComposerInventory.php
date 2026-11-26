<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * What the project has installed with Composer: what can be asked about, and how much cannot.
 *
 * @internal
 */
final readonly class ComposerInventory
{
    /**
     * @param  list<ComposerPackage>  $packages  releases of packages that come from Packagist
     * @param  int  $elsewhere  packages that do not come from Packagist: counted, never named
     * @param  list<string>  $branches  names of Packagist packages installed from a branch, which has no version to ask about
     */
    public function __construct(
        public array $packages,
        public int $elsewhere = 0,
        public array $branches = [],
    ) {}
}
