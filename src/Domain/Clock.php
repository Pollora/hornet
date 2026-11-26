<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain;

use DateTimeImmutable;

/**
 * The time, from a port: a test sets it, and nothing in the domain reads the system clock.
 *
 * @internal
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
