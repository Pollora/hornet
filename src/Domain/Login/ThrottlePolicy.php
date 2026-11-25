<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Login;

/**
 * How many failures an address may make, over how long, and how long it is shut out for.
 *
 * Per address only, never per user name: locking a user name would let anyone lock the owner out by guessing wrong on purpose.
 * A lockout is longer each time the same address earns one, up to a day.
 */
final readonly class ThrottlePolicy
{
    private const int LONGEST = 86400;

    public function __construct(
        public int $maxAttempts = 5,
        /** Seconds over which failures are counted. */
        public int $window = 900,
        /** Seconds of the first lockout; each later one doubles. */
        public int $lockout = 900,
    ) {}

    public function isLocked(Attempts $attempts, int $now): bool
    {
        return $attempts->lockedUntil > $now;
    }

    /**
     * The state after one more failure.
     */
    public function failed(Attempts $attempts, int $now): Attempts
    {
        // Already locked: more failures change nothing, and do not stretch the lock
        if ($this->isLocked($attempts, $now)) {
            return $attempts;
        }

        $fresh = $attempts->windowStart === 0 || $attempts->windowStart + $this->window <= $now;
        $failures = ($fresh ? 0 : $attempts->failures) + 1;
        $start = $fresh ? $now : $attempts->windowStart;

        if ($failures < $this->maxAttempts) {
            return new Attempts($failures, $start, 0, $attempts->lockouts);
        }

        $duration = min($this->lockout * (2 ** min($attempts->lockouts, 10)), self::LONGEST);

        return new Attempts(0, 0, $now + $duration, $attempts->lockouts + 1);
    }

    /**
     * After a successful login the failures are forgotten; the history of lockouts is not.
     */
    public function succeeded(Attempts $attempts): Attempts
    {
        return new Attempts(0, 0, 0, $attempts->lockouts);
    }

    public function secondsLeft(Attempts $attempts, int $now): int
    {
        return max($attempts->lockedUntil - $now, 0);
    }
}
