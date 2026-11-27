<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Login;

use Pollora\Hornet\Domain\Login\Attempts;
use Pollora\Hornet\Domain\Login\AttemptStore;

/**
 * The failures of each address, kept under a hash of the address.
 *
 * With a persistent object cache (Redis, Memcached: pollora/object-cache) they live in the cache, which is made for this and
 * costs the database nothing. Without one they are transients, which are rows of the options table: a flood of different
 * addresses then writes as many rows, which is one more reason to run an object cache.
 *
 * The lockouts are also listed in one option, so that `wp hornet unlock --all` can find them: a cache cannot be listed.
 *
 * On a multisite network the accounts are shared by every site, so the failures, the locks and their list are the network's:
 * a site transient, a global cache group and a network option.
 *
 * @internal
 */
final class TransientAttemptStore implements AttemptStore
{
    private const string GROUP = 'hornet-login';

    private const string INDEX = 'hornet_lockouts';

    /** The most lockouts the index remembers: the others still lock, they just cannot be unlocked all at once. */
    private const int INDEX_CAP = 500;

    public function __construct()
    {
        if (function_exists('is_multisite') && is_multisite()) {
            wp_cache_add_global_groups([self::GROUP]);
        }
    }

    public function get(string $key): Attempts
    {
        $stored = match (true) {
            wp_using_ext_object_cache() => wp_cache_get($key, self::GROUP),
            is_multisite() => get_site_transient('hornet_lg_'.$key),
            default => get_transient('hornet_lg_'.$key),
        };

        return Attempts::fromArray($stored);
    }

    public function put(string $key, Attempts $attempts, int $keepFor): void
    {
        if (wp_using_ext_object_cache()) {
            wp_cache_set($key, $attempts->toArray(), self::GROUP, $keepFor);
        } elseif (is_multisite()) {
            set_site_transient('hornet_lg_'.$key, $attempts->toArray(), $keepFor);
        } else {
            set_transient('hornet_lg_'.$key, $attempts->toArray(), $keepFor);
        }

        if ($attempts->lockedUntil > time()) {
            $this->index($key, $attempts->lockedUntil);
        }
    }

    public function forget(string $key): void
    {
        $this->delete($key);

        $index = $this->read();
        unset($index[$key]);
        $this->write($index);
    }

    public function forgetAll(): int
    {
        $locked = 0;
        $now = time();

        foreach ($this->read() as $key => $until) {
            $locked += $until > $now ? 1 : 0;
            $this->delete($key);
        }

        $this->write([]);

        return $locked;
    }

    private function delete(string $key): void
    {
        match (true) {
            wp_using_ext_object_cache() => wp_cache_delete($key, self::GROUP),
            is_multisite() => delete_site_transient('hornet_lg_'.$key),
            default => delete_transient('hornet_lg_'.$key),
        };
    }

    private function index(string $key, int $until): void
    {
        $now = time();
        $index = array_filter($this->read(), static fn (int $expires): bool => $expires > $now);
        $index[$key] = $until;

        if (count($index) > self::INDEX_CAP) {
            asort($index);
            $index = array_slice($index, -self::INDEX_CAP, null, true);
        }

        $this->write($index);
    }

    /**
     * @return array<string, int>
     */
    private function read(): array
    {
        $stored = is_multisite() ? get_site_option(self::INDEX, []) : get_option(self::INDEX, []);

        return is_array($stored) ? array_filter($stored, static fn (mixed $until, mixed $key): bool => is_int($until) && is_string($key) && preg_match('/\A[a-f0-9]{64}\z/', $key) === 1, ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * @param  array<string, int>  $index
     */
    private function write(array $index): void
    {
        if (is_multisite()) {
            $index === [] ? delete_site_option(self::INDEX) : update_site_option(self::INDEX, $index);

            return;
        }

        $index === [] ? delete_option(self::INDEX) : update_option(self::INDEX, $index, false);
    }
}
