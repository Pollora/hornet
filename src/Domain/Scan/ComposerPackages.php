<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * @internal
 */
interface ComposerPackages
{
    /**
     * Null when the project has no list of packages that can be read.
     */
    public function inventory(): ?ComposerInventory;
}
