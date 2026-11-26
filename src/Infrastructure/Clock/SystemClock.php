<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Pollora\Hornet\Domain\Clock;

/**
 * The system clock, in UTC.
 *
 * @internal
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
