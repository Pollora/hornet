<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Audit;

use Closure;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\Audit\SetAsideStore;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Exceptions\SetAsideException;
use Pollora\Hornet\Domain\Journal\EventDetails;
use Pollora\Hornet\Domain\Journal\EventTypes;

/**
 * Sets a finding aside, or brings it back. Setting aside hides a problem, so it asks for a reason, remembers who gave it and when, and says it in the journal.
 *
 * What the settings file sets aside wins and cannot be changed from here. Only a finding that is open can be set aside: a check that passes has nothing to hide.
 *
 * @internal
 */
final readonly class SetAsideService
{
    public const int MIN_REASON = 8;

    public const int MAX_REASON = 300;

    /**
     * @param  Closure(): list<string>  $known  the ids of the checks that exist
     * @param  Closure(): list<string>  $open  the ids of the checks whose finding is open now
     * @param  Closure(): list<string>  $inFile  the ids that the settings file sets aside
     * @param  Closure(string, string, array<string, string|int|bool>): mixed  $journal  writes an event (type, subject, details); it may do nothing
     */
    public function __construct(
        private SetAsideStore $store,
        private Clock $clock,
        private Closure $known,
        private Closure $open,
        private Closure $inFile,
        private Closure $journal,
    ) {}

    /**
     * @throws SetAsideException
     */
    public function setAside(string $check, string $reason, int $userId, string $login): SetAside
    {
        $reason = trim(EventDetails::text($reason, self::MAX_REASON));

        if (! in_array($check, ($this->known)(), true)) {
            throw SetAsideException::unknownCheck($check);
        }

        if (in_array($check, ($this->inFile)(), true)) {
            throw SetAsideException::lockedByFile($check);
        }

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw SetAsideException::reasonNeeded(self::MIN_REASON);
        }

        // A reason can be changed on a finding that is already set aside; a new one needs something open
        if (! in_array($check, ($this->open)(), true) && ! isset($this->store->all()[$check])) {
            throw SetAsideException::notOpen($check);
        }

        $aside = new SetAside($check, $reason, $userId, EventDetails::text($login, 60), $this->clock->now()->getTimestamp());
        $this->store->put($aside);
        ($this->journal)(EventTypes::FINDING_SET_ASIDE, 'check:'.$check, ['reason' => $reason]);

        return $aside;
    }

    /**
     * @throws SetAsideException
     */
    public function restore(string $check): void
    {
        if (! in_array($check, ($this->known)(), true)) {
            throw SetAsideException::unknownCheck($check);
        }

        if (in_array($check, ($this->inFile)(), true)) {
            throw SetAsideException::lockedByFile($check);
        }

        if (! isset($this->store->all()[$check])) {
            return;
        }

        $this->store->remove($check);
        ($this->journal)(EventTypes::FINDING_RESTORED, 'check:'.$check, []);
    }
}
