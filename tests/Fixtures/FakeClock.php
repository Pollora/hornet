<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Pollora\Hornet\Domain\Clock;

/**
 * A clock that a test sets and moves.
 */
final class FakeClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $at = '2026-11-25 10:00:00')
    {
        $this->now = new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->add(new DateInterval($interval));
    }
}
