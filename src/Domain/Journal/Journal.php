<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Journal;

use DateTimeImmutable;

/**
 * Where the events are kept.
 *
 * @internal
 */
interface Journal
{
    public function record(Event $event): void;

    /**
     * @return list<Event> newest first
     */
    public function find(JournalQuery $query): array;

    public function count(JournalQuery $query): int;

    /**
     * Forgets what is older than this.
     *
     * @return int how many events were removed
     */
    public function purgeBefore(DateTimeImmutable $cutoff): int;

    /**
     * The events that name a person, as the one who did it or the one it was done to.
     *
     * @return list<Event>
     */
    public function aboutUser(int $userId, int $limit, int $offset = 0): array;

    /**
     * Takes a person out of the events that name them, and keeps the events (a security journal that forgets what happened is no journal):
     * their number, their login and the address are blanked.
     *
     * @return int how many events were changed
     */
    public function anonymizeUser(int $userId): int;
}
