<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Notification;

use DateTimeImmutable;

/**
 * Messages waiting for another attempt.
 *
 * @internal
 */
interface RetryQueue
{
    public function push(QueuedDelivery $delivery): void;

    /**
     * @return list<QueuedDelivery>
     */
    public function due(DateTimeImmutable $now): array;

    public function remove(string $id): void;

    public function count(): int;
}
