<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

/**
 * One piece of the package (Site Health, later the headers…): it attaches itself to WordPress and nothing else.
 */
interface Module
{
    public function register(HookRegistrar $hooks): void;
}
