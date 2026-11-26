<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

/**
 * How much a failed check matters. `Info` is advice, `Critical` is an emergency.
 *
 * @internal
 */
enum Severity: string
{
    case Info = 'info';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
