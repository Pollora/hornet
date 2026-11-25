<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Journal;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Pollora\Hornet\Domain\Exceptions\JournalException;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\Journal;
use Pollora\Hornet\Domain\Journal\JournalQuery;
use Pollora\Hornet\Domain\Journal\Source;

/**
 * The journal, in its table. Every value goes through `$wpdb->prepare()` or `$wpdb->insert()`: nothing is built by hand.
 */
final readonly class TableJournal implements Journal
{
    public function record(Event $event): void
    {
        global $wpdb;

        $done = $wpdb->insert(JournalSchema::table(), [
            'occurred_at' => $event->at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'type' => $event->type,
            'source' => $event->source->value,
            'user_id' => $event->userId,
            'actor' => $event->actor,
            'subject' => $event->subject,
            'ip' => $event->ip,
            'details' => (string) json_encode($event->details, JSON_UNESCAPED_SLASHES),
        ], ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']);

        if ($done === false) {
            throw JournalException::notWritten();
        }
    }

    public function find(JournalQuery $query): array
    {
        global $wpdb;

        [$where, $values] = $this->where($query);

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i '.$where.' ORDER BY occurred_at DESC, id DESC LIMIT %d OFFSET %d', [JournalSchema::table(), ...$values, $query->limit, $query->offset]), ARRAY_A);

        return array_values(array_filter(array_map(self::event(...), is_array($rows) ? $rows : [])));
    }

    public function count(JournalQuery $query): int
    {
        global $wpdb;

        [$where, $values] = $this->where($query);

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i '.$where, [JournalSchema::table(), ...$values]));
    }

    public function purgeBefore(DateTimeImmutable $cutoff): int
    {
        global $wpdb;

        $removed = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE occurred_at < %s', JournalSchema::table(), $cutoff->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')));

        return is_int($removed) ? $removed : 0;
    }

    public function aboutUser(int $userId, int $limit, int $offset = 0): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE user_id = %d OR subject = %s ORDER BY occurred_at DESC, id DESC LIMIT %d OFFSET %d', JournalSchema::table(), $userId, 'user:'.$userId, max(1, $limit), max(0, $offset)), ARRAY_A);

        return array_values(array_filter(array_map(self::event(...), is_array($rows) ? $rows : [])));
    }

    public function anonymizeUser(int $userId): int
    {
        global $wpdb;

        $by = $wpdb->query($wpdb->prepare("UPDATE %i SET user_id = 0, actor = '', ip = '' WHERE user_id = %d", JournalSchema::table(), $userId));
        // What was done to them: the number goes, the event stays
        $to = $wpdb->query($wpdb->prepare('UPDATE %i SET subject = %s WHERE subject = %s', JournalSchema::table(), 'user:erased', 'user:'.$userId));

        return (is_int($by) ? $by : 0) + (is_int($to) ? $to : 0);
    }

    /**
     * @return array{string, list<string|int>} the WHERE clause, and the values that go in it
     */
    private function where(JournalQuery $query): array
    {
        global $wpdb;

        $clauses = [];
        $values = [];

        if ($query->type !== null && $query->type !== '') {
            if (str_ends_with($query->type, '.')) {
                $clauses[] = 'type LIKE %s';
                $values[] = $wpdb->esc_like($query->type).'%';
            } else {
                $clauses[] = 'type = %s';
                $values[] = $query->type;
            }
        }

        if ($query->source instanceof Source) {
            $clauses[] = 'source = %s';
            $values[] = $query->source->value;
        }

        if ($query->userId !== null) {
            $clauses[] = 'user_id = %d';
            $values[] = $query->userId;
        }

        if ($query->since instanceof DateTimeImmutable) {
            $clauses[] = 'occurred_at >= %s';
            $values[] = $query->since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        return [$clauses === [] ? '' : 'WHERE '.implode(' AND ', $clauses), $values];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function event(array $row): ?Event
    {
        $source = is_string($row['source'] ?? null) ? Source::tryFrom($row['source']) : null;

        if (! $source instanceof Source || ! is_string($row['occurred_at'] ?? null) || ! is_string($row['type'] ?? null)) {
            return null;
        }

        try {
            $details = json_decode(is_string($row['details'] ?? null) ? $row['details'] : '[]', true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $details = [];
        }

        return new Event(
            new DateTimeImmutable($row['occurred_at'], new DateTimeZone('UTC')),
            $row['type'],
            $source,
            (int) ($row['user_id'] ?? 0),
            is_string($row['actor'] ?? null) ? $row['actor'] : '',
            is_string($row['subject'] ?? null) ? $row['subject'] : '',
            is_string($row['ip'] ?? null) ? $row['ip'] : '',
            is_array($details) ? array_filter($details, is_scalar(...)) : [],
            (int) ($row['id'] ?? 0),
        );
    }
}
