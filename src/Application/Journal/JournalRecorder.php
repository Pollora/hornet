<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Journal;

use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\EventDetails;
use Pollora\Hornet\Domain\Journal\EventTypes;
use Pollora\Hornet\Domain\Journal\IpAnonymizer;
use Pollora\Hornet\Domain\Journal\Journal;
use Throwable;

/**
 * Writes what happened in the journal, from the places that saw it.
 *
 * It never gets in the way: a journal that cannot be written (the table is gone, the database is slow) must not stop a login, a plugin
 * update or a page. The failure is counted, not thrown.
 */
final class JournalRecorder
{
    private int $failures = 0;

    public function __construct(
        private readonly Journal $journal,
        private readonly RequestContext $request,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  string  $subject  what it is about (`user:12`, `plugin:akismet`)
     * @param  array<mixed>  $details  a few facts: cleaned, and never a secret
     * @param  int|null  $userId  who did it, when it is not the person logged in (a login: the one who logs in)
     * @param  string|null  $actor  the login of that person
     * @return bool whether it was written
     */
    public function record(string $type, string $subject = '', array $details = [], ?int $userId = null, ?string $actor = null): bool
    {
        if (! EventTypes::exists($type)) {
            return false;
        }

        try {
            $userId ??= $this->request->userId();
            $actor ??= $userId === $this->request->userId() ? $this->request->userLogin() : '';

            $this->journal->record(new Event(
                $this->clock->now(),
                $type,
                $this->request->source(),
                max(0, $userId),
                EventDetails::text($actor, 60),
                EventDetails::text($subject, 120),
                IpAnonymizer::truncate($this->request->ip()),
                EventDetails::clean($details),
            ));

            return true;
        } catch (Throwable) {
            $this->failures++;

            return false;
        }
    }

    /**
     * How many events could not be written since the start of this request.
     */
    public function failures(): int
    {
        return $this->failures;
    }
}
