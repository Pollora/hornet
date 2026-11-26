<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pollora\Hornet\Domain\Notification\NotificationState;

/**
 * What each channel was told, and when, in one option that is not loaded on every request.
 *
 * Only the audit writes it, and the audit holds a lock: no two writers meet. Entries older than 60 days are dropped as they are written.
 *
 * @internal
 */
final class OptionNotificationState implements NotificationState
{
    private const string OPTION = 'hornet_notify_state';

    private const int KEEP_TOLD = 5184000;

    private const int KEEP_SENT = 3600;

    public function lastTold(string $channel, string $alertKey): ?DateTimeImmutable
    {
        $time = $this->read()['told'][$channel][$alertKey] ?? null;

        return is_int($time) ? (new DateTimeImmutable('@'.$time))->setTimezone(new DateTimeZone('UTC')) : null;
    }

    public function remember(string $channel, array $alertKeys, DateTimeImmutable $at): void
    {
        $state = $this->read();

        foreach ($alertKeys as $key) {
            $state['told'][$channel][$key] = $at->getTimestamp();
        }

        $this->write($state, $at->getTimestamp());
    }

    public function forget(string $channel, array $alertKeys): void
    {
        $state = $this->read();

        foreach ($alertKeys as $key) {
            unset($state['told'][$channel][$key]);
        }

        $this->write($state, time());
    }

    public function sentSince(string $channel, DateTimeImmutable $since): int
    {
        return count(array_filter($this->read()['sent'][$channel] ?? [], static fn (int $time): bool => $time >= $since->getTimestamp()));
    }

    public function recordSent(string $channel, DateTimeImmutable $at): void
    {
        $state = $this->read();
        $state['sent'][$channel][] = $at->getTimestamp();
        $this->write($state, $at->getTimestamp());
    }

    /**
     * @return array{told: array<string, array<string, int>>, sent: array<string, list<int>>}
     */
    private function read(): array
    {
        $stored = get_option(self::OPTION, []);
        $told = [];
        $sent = [];

        foreach (is_array($stored) && is_array($stored['told'] ?? null) ? $stored['told'] : [] as $channel => $keys) {
            foreach (is_array($keys) ? $keys : [] as $key => $time) {
                if (is_int($time)) {
                    $told[(string) $channel][(string) $key] = $time;
                }
            }
        }

        foreach (is_array($stored) && is_array($stored['sent'] ?? null) ? $stored['sent'] : [] as $channel => $times) {
            $sent[(string) $channel] = array_values(array_filter(is_array($times) ? $times : [], is_int(...)));
        }

        return ['told' => $told, 'sent' => $sent];
    }

    /**
     * @param  array{told: array<string, array<string, int>>, sent: array<string, list<int>>}  $state
     */
    private function write(array $state, int $now): void
    {
        foreach ($state['told'] as $channel => $keys) {
            $state['told'][$channel] = array_filter($keys, static fn (int $time): bool => $time >= $now - self::KEEP_TOLD);
        }

        foreach ($state['sent'] as $channel => $times) {
            $state['sent'][$channel] = array_values(array_filter($times, static fn (int $time): bool => $time >= $now - self::KEEP_SENT));
        }

        // Not autoloaded: it is read by the cron and the commands, never by a page view
        update_option(self::OPTION, $state, false);
    }
}
