<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Brain\Monkey\Functions;

/**
 * Where WordPress keeps things, as arrays: options (of the site and of the network), transients, the object cache and user
 * meta. A test reads back what was written, with the autoload flag and the lifetime, and can put damaged values in.
 *
 * Like the database, `get_option()` gives a stored number back as text when `$likeTheDatabase` says so.
 */
final class FakeSite
{
    /** @var array<string, mixed> */
    public array $options = [];

    /** @var array<string, mixed> */
    public array $networkOptions = [];

    /** @var array<string, mixed> */
    public array $transients = [];

    /** @var array<string, mixed> */
    public array $siteTransients = [];

    /** @var array<string, int> the lifetime asked for each transient and cache entry */
    public array $lifetimes = [];

    /** @var array<string, mixed> by "group:key" */
    public array $cache = [];

    /** @var array<int, array<string, mixed>> */
    public array $userMeta = [];

    /** @var array<string, bool> the autoload flag an option was created or written with */
    public array $autoload = [];

    /** @var list<string> the cache groups that every site of a network reads */
    public array $globalGroups = [];

    public bool $multisite = false;

    public bool $objectCache = false;

    public bool $likeTheDatabase = false;

    public static function install(): self
    {
        $site = new self;
        $site->stub();

        return $site;
    }

    private function stub(): void
    {
        Functions\when('is_multisite')->alias(fn (): bool => $this->multisite);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            if (! array_key_exists($name, $this->options)) {
                return $default;
            }

            return $this->likeTheDatabase && is_int($this->options[$name]) ? (string) $this->options[$name] : $this->options[$name];
        });
        Functions\when('add_option')->alias(function (string $name, mixed $value, string $deprecated = '', bool $autoload = true): bool {
            if (array_key_exists($name, $this->options)) {
                return false;
            }

            $this->options[$name] = $value;
            $this->autoload[$name] = $autoload;

            return true;
        });
        Functions\when('update_option')->alias(function (string $name, mixed $value, ?bool $autoload = null): bool {
            $this->options[$name] = $value;

            if ($autoload !== null) {
                $this->autoload[$name] = $autoload;
            }

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            $existed = array_key_exists($name, $this->options);
            unset($this->options[$name]);

            return $existed;
        });

        Functions\when('get_network_option')->alias(fn (?int $network, string $name, mixed $default = false): mixed => $this->networkOptions[$name] ?? $default);
        Functions\when('update_network_option')->alias(function (?int $network, string $name, mixed $value): bool {
            $this->networkOptions[$name] = $value;

            return true;
        });
        Functions\when('get_site_option')->alias(fn (string $name, mixed $default = false): mixed => $this->networkOptions[$name] ?? $default);
        Functions\when('update_site_option')->alias(function (string $name, mixed $value): bool {
            $this->networkOptions[$name] = $value;

            return true;
        });

        Functions\when('delete_site_option')->alias(function (string $name): bool {
            unset($this->networkOptions[$name]);

            return true;
        });
        Functions\when('delete_site_transient')->alias(function (string $name): bool {
            unset($this->siteTransients[$name]);

            return true;
        });
        Functions\when('wp_cache_add_global_groups')->alias(function (array $groups): void {
            $this->globalGroups = [...$this->globalGroups, ...$groups];
        });

        Functions\when('get_transient')->alias(fn (string $name): mixed => $this->transients[$name] ?? false);
        Functions\when('set_transient')->alias(function (string $name, mixed $value, int $seconds = 0): bool {
            $this->transients[$name] = $value;
            $this->lifetimes[$name] = $seconds;

            return true;
        });
        Functions\when('delete_transient')->alias(function (string $name): bool {
            unset($this->transients[$name]);

            return true;
        });
        Functions\when('get_site_transient')->alias(fn (string $name): mixed => $this->siteTransients[$name] ?? false);
        Functions\when('set_site_transient')->alias(function (string $name, mixed $value, int $seconds = 0): bool {
            $this->siteTransients[$name] = $value;
            $this->lifetimes[$name] = $seconds;

            return true;
        });

        Functions\when('wp_using_ext_object_cache')->alias(fn (): bool => $this->objectCache);
        Functions\when('wp_cache_get')->alias(fn (string $key, string $group = ''): mixed => $this->cache[$group.':'.$key] ?? false);
        Functions\when('wp_cache_set')->alias(function (string $key, mixed $value, string $group = '', int $seconds = 0): bool {
            $this->cache[$group.':'.$key] = $value;
            $this->lifetimes[$group.':'.$key] = $seconds;

            return true;
        });
        Functions\when('wp_cache_add')->alias(function (string $key, mixed $value, string $group = '', int $seconds = 0): bool {
            if (array_key_exists($group.':'.$key, $this->cache)) {
                return false;
            }

            $this->cache[$group.':'.$key] = $value;
            $this->lifetimes[$group.':'.$key] = $seconds;

            return true;
        });
        Functions\when('wp_cache_incr')->alias(function (string $key, int $by = 1, string $group = ''): int|false {
            $current = $this->cache[$group.':'.$key] ?? false;

            return is_int($current) ? $this->cache[$group.':'.$key] = $current + $by : false;
        });
        Functions\when('wp_cache_delete')->alias(function (string $key, string $group = ''): bool {
            unset($this->cache[$group.':'.$key]);

            return true;
        });

        Functions\when('get_user_meta')->alias(fn (int $user, string $key, bool $single = false): mixed => $this->userMeta[$user][$key] ?? '');
        Functions\when('update_user_meta')->alias(function (int $user, string $key, mixed $value): bool {
            $this->userMeta[$user][$key] = $value;

            return true;
        });
        Functions\when('delete_user_meta')->alias(function (int $user, string $key): bool {
            $existed = isset($this->userMeta[$user][$key]);
            unset($this->userMeta[$user][$key]);

            return $existed;
        });
    }
}
