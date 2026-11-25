<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use DateTimeImmutable;
use Pollora\Hornet\Domain\Notification\NotificationState;

final class InMemoryNotificationState implements NotificationState
{
    /** @var array<string, array<string, DateTimeImmutable>> */
    public array $told = [];

    /** @var array<string, list<DateTimeImmutable>> */
    public array $sent = [];

    public function lastTold(string $channel, string $alertKey): ?DateTimeImmutable
    {
        return $this->told[$channel][$alertKey] ?? null;
    }

    public function remember(string $channel, array $alertKeys, DateTimeImmutable $at): void
    {
        foreach ($alertKeys as $key) {
            $this->told[$channel][$key] = $at;
        }
    }

    public function forget(string $channel, array $alertKeys): void
    {
        foreach ($alertKeys as $key) {
            unset($this->told[$channel][$key]);
        }
    }

    public function sentSince(string $channel, DateTimeImmutable $since): int
    {
        return count(array_filter($this->sent[$channel] ?? [], static fn (DateTimeImmutable $at): bool => $at >= $since));
    }

    public function recordSent(string $channel, DateTimeImmutable $at): void
    {
        $this->sent[$channel][] = $at;
    }
}
