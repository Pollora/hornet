<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Journal;

use Pollora\Hornet\Domain\Journal\WindowCounter;

/**
 * A counter in the object cache when the site has a persistent one (an atomic increment, no write to the database), and in a transient
 * otherwise. The key is hashed: it holds a network, and a transient name is short.
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
        $count = get_transient($name);
        $count = (is_numeric($count) ? (int) $count : 0) + 1;
        set_transient($name, $count, $window);

        return $count;
    }
}
