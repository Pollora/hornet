<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Notification;

/**
 * How long to wait before the next attempt, and when to stop: 1 minute, 5 minutes, 30 minutes, 2 hours, then give up.
 */
final readonly class Backoff
{
    /** @var list<int> */
    private const array DELAYS = [60, 300, 1800, 7200];

    /** The longest a service may ask us to wait: more would hold the queue for a day. */
    private const int LONGEST = 3600;

    public function maxAttempts(): int
    {
        return count(self::DELAYS) + 1;
    }

    /**
     * Seconds before attempt number $attempt + 1, given that $attempt attempts have failed. A service's own Retry-After
     * wins when it asks for more, within a limit.
     */
    public function delay(int $attempt, ?int $retryAfter = null): int
    {
        $own = self::DELAYS[min(max($attempt, 1), count(self::DELAYS)) - 1];

        return max($own, min(max($retryAfter ?? 0, 0), self::LONGEST));
    }

    public function exhausted(int $attempts): bool
    {
        return $attempts >= $this->maxAttempts();
    }
}
