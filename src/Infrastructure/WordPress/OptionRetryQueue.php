<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Domain\Notification\QueuedDelivery;
use Pollora\Hornet\Domain\Notification\RetryQueue;

/**
 * Waiting messages, one option each (`hornet_nq_<id>`), so that two writers never overwrite each other's list.
 * The queue is capped: when a service stays down for days the oldest news is not worth a growing table.
 *
 * @internal
 */
final class OptionRetryQueue implements RetryQueue
{
    private const string PREFIX = 'hornet_nq_';

    private const int CAP = 100;

    public function push(QueuedDelivery $delivery): void
    {
        if (! $this->validId($delivery->id)) {
            return;
        }

        if ($this->count() >= self::CAP && get_option(self::PREFIX.$delivery->id) === false) {
            return;
        }

        update_option(self::PREFIX.$delivery->id, (string) wp_json_encode([
            'channel' => $delivery->channel,
            'attempts' => $delivery->attempts,
            'due' => $delivery->dueAt->getTimestamp(),
            'notification' => $delivery->notification->toArray(),
        ]), false);
    }

    public function due(DateTimeImmutable $now): array
    {
        $due = [];

        foreach ($this->rows() as $name => $value) {
            $id = substr($name, strlen(self::PREFIX));
            $data = json_decode($value, true);
            $notification = is_array($data) && is_array($data['notification'] ?? null) ? Notification::fromArray($data['notification']) : null;

            if (! $this->validId($id) || ! is_array($data) || ! $notification instanceof Notification || ! is_string($data['channel'] ?? null) || ! is_int($data['attempts'] ?? null) || ! is_int($data['due'] ?? null)) {
                // Not ours, or damaged: it is not worth keeping
                delete_option($name);

                continue;
            }

            if ($data['due'] <= $now->getTimestamp()) {
                $due[] = new QueuedDelivery($id, $data['channel'], $notification, $data['attempts'], (new DateTimeImmutable('@'.$data['due']))->setTimezone(new DateTimeZone('UTC')));
            }
        }

        return $due;
    }

    public function remove(string $id): void
    {
        if ($this->validId($id)) {
            delete_option(self::PREFIX.$id);
        }
    }

    public function count(): int
    {
        return count($this->rows());
    }

    private function validId(string $id): bool
    {
        return preg_match('/\A[a-f0-9]{16}\z/', $id) === 1;
    }

    /**
     * @return array<string, string>
     */
    private function rows(): array
    {
        $wpdb = Database::connection();

        $rows = $wpdb->get_results($wpdb->prepare('SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d', $wpdb->options, $wpdb->esc_like(self::PREFIX).'%', self::CAP + 50), ARRAY_A);
        $found = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['option_name'] ?? null) && is_string($row['option_value'] ?? null)) {
                $found[$row['option_name']] = $row['option_value'];
            }
        }

        return $found;
    }
}
