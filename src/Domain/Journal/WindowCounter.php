<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Journal;

/**
 * Counts how often something happened in a stretch of time, without keeping a row for each time: in the object cache when there is one, in a
 * transient otherwise. It is what keeps a visitor who sends ten thousand failed logins from writing ten thousand rows.
 *
 * @internal
 */
interface WindowCounter
{
    /**
     * Counts one more, and says how many there have been in the current window.
     *
     * @param  int  $window  seconds
     */
    public function hit(string $key, int $window): int;
}
