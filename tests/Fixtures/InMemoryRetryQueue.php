<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use DateTimeImmutable;
use Pollora\Hornet\Domain\Notification\QueuedDelivery;
use Pollora\Hornet\Domain\Notification\RetryQueue;

class InMemoryRetryQueue implements RetryQueue
{
    /** @var array<string, QueuedDelivery> */
    public array $items = [];

    public function push(QueuedDelivery $delivery): void
    {
        $this->items[$delivery->id] = $delivery;
    }

    public function due(DateTimeImmutable $now): array
    {
        return array_values(array_filter($this->items, static fn (QueuedDelivery $item): bool => $item->dueAt <= $now));
    }

    public function remove(string $id): void
    {
        unset($this->items[$id]);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
