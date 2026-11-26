<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Journal;

use Pollora\Hornet\Domain\Journal\WindowCounter;

/**
 * A counter in the object cache when the site has a persistent one (an atomic increment, no write to the database), and in a transient
 * otherwise. The key is hashed: it holds a network, and a transient name is short.
 *
 * @internal
 */
final class CacheWindowCounter implements WindowCounter
{
    private const string GROUP = 'hornet';

    public function hit(string $key, int $window): int
    {
        $name = 'hornet_jr_'.md5($key);

        if (wp_using_ext_object_cache()) {
            wp_cache_add($name, 0, self::GROUP, $window);
            $count = wp_cache_incr($name, 1, self::GROUP);

            return is_int($count) ? $count : 1;
        }

        // A transient comes back from the database as text
        // The window starts at the first hit and ends where it was meant to: it does not slide with each hit, or somebody who keeps calling
        // would keep a limit shut for ever. Without an object cache the read and the write are not atomic: parallel calls may slip a few
        // past the limit, which is a bound on the volume, not a lock.
        $stored = get_transient($name);
        [$count, $ends] = is_string($stored) && preg_match('/^(\d{1,9}):(\d{10})$/D', $stored, $m) === 1 ? [(int) $m[1], (int) $m[2]] : [0, time() + $window];
        $count++;
        set_transient($name, $count.':'.$ends, max(1, $ends - time()));

        return $count;
    }
}
