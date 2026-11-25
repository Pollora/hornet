<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use DateTimeImmutable;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\Journal;
use Pollora\Hornet\Domain\Journal\JournalQuery;

final class InMemoryJournal implements Journal
{
    /** @var list<Event> */
    public array $events = [];

    public ?\Throwable $failure = null;

    public function record(Event $event): void
    {
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        $this->events[] = $event;
    }

    public function find(JournalQuery $query): array
    {
        $found = array_values(array_filter($this->events, static fn (Event $event): bool => ($query->type === null || ($query->type !== '' && str_ends_with($query->type, '.') ? str_starts_with($event->type, $query->type) : $event->type === $query->type))
            && ($query->source === null || $event->source === $query->source)
            && ($query->userId === null || $event->userId === $query->userId)
            && ($query->since === null || $event->at >= $query->since)));

        return array_slice(array_reverse($found), $query->offset, $query->limit);
    }

    public function count(JournalQuery $query): int
    {
        return count($this->find(new JournalQuery($query->type, $query->source, $query->userId, $query->since, JournalQuery::MAX)));
    }

    public function purgeBefore(DateTimeImmutable $cutoff): int
    {
        $before = count($this->events);
        $this->events = array_values(array_filter($this->events, static fn (Event $event): bool => $event->at >= $cutoff));

        return $before - count($this->events);
    }

    public function aboutUser(int $userId, int $limit, int $offset = 0): array
    {
        return array_slice(array_values(array_filter($this->events, static fn (Event $event): bool => $event->userId === $userId || $event->subject === 'user:'.$userId)), $offset, $limit);
    }

    public function anonymizeUser(int $userId): int
    {
        $changed = 0;

        foreach ($this->events as $index => $event) {
            if ($event->userId === $userId || $event->subject === 'user:'.$userId) {
                $this->events[$index] = new Event($event->at, $event->type, $event->source, $event->userId === $userId ? 0 : $event->userId, $event->userId === $userId ? '' : $event->actor, $event->subject === 'user:'.$userId ? 'user:erased' : $event->subject, $event->userId === $userId ? '' : $event->ip, $event->details, $event->id);
                $changed++;
            }
        }

        return $changed;
    }
}
