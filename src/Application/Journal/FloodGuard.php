<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Journal;

use Pollora\Hornet\Domain\Journal\WindowCounter;

/**
 * Keeps what a visitor who is not logged in can make the site write within bounds.
 *
 * A failed login is the one event that anybody can cause as often as they like. Without a bound, a botnet that guesses passwords writes a row
 * each time, and the table grows by the megabyte a day until the purge. The first few failures of a network are written; at the next one, a
 * single `login.flood` event says that the network went past the limit; the rest are counted and not written. The same holds for the site as
 * a whole, per hour, since a botnet that comes from thousands of networks goes round a limit per network.
 *
 * The worst case is therefore {@see Decision} `perHour` rows an hour for failed logins, whatever is sent.
 *
 * A `scope` counts apart: refused second factors have their own, so that a burst of wrong passwords cannot use up the room and hide
 * them. The worst case is then `perHour` rows an hour for each scope.
 *
 * @internal
 */
final readonly class FloodGuard
{
    public function __construct(
        private WindowCounter $counter,
        private int $perNetwork = 5,
        private int $window = 600,
        private int $perHour = 120,
        private string $scope = 'journal',
    ) {}

    /**
     * @param  string  $network  who is asking, as the journal keeps it (the shortened address): empty when it is not known
     */
    public function decide(string $network): Decision
    {
        $hour = $this->counter->hit($this->scope.':hour', 3600);

        if ($hour > $this->perHour + 1) {
            return Decision::Drop;
        }

        if ($hour === $this->perHour + 1) {
            return Decision::FloodSite;
        }

        $count = $this->counter->hit($this->scope.':network:'.($network === '' ? 'unknown' : $network), $this->window);

        return match (true) {
            $count <= $this->perNetwork => Decision::Write,
            $count === $this->perNetwork + 1 => Decision::FloodNetwork,
            default => Decision::Drop,
        };
    }

    public function perNetwork(): int
    {
        return $this->perNetwork;
    }

    public function window(): int
    {
        return $this->window;
    }

    public function perHour(): int
    {
        return $this->perHour;
    }
}
