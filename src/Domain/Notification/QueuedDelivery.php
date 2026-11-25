<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Notification;

use DateTimeImmutable;

/**
 * A message that did not go through and will be tried again.
 */
final readonly class QueuedDelivery
{
    public function __construct(
        public string $id,
        public string $channel,
        public Notification $notification,
        public int $attempts,
        public DateTimeImmutable $dueAt,
    ) {}
}
