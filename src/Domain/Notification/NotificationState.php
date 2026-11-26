<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Notification;

use DateTimeImmutable;

/**
 * What the notifier remembers between two runs: what each channel was told and when, so that it is not told twice,
 * and how many messages it got in the last hour.
 *
 * @internal
 */
interface NotificationState
{
    public function lastTold(string $channel, string $alertKey): ?DateTimeImmutable;

    /**
     * @param  list<string>  $alertKeys
     */
    public function remember(string $channel, array $alertKeys, DateTimeImmutable $at): void;

    /**
     * @param  list<string>  $alertKeys
     */
    public function forget(string $channel, array $alertKeys): void;

    public function sentSince(string $channel, DateTimeImmutable $since): int;

    public function recordSent(string $channel, DateTimeImmutable $at): void;
}
